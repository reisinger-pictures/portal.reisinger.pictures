<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ModelProfileReminderMail;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Lifecycle-Expiry (Hard-Delete nach 15 Monaten) und lifecycle-basierte
 * Admin-Liste (Default nur aktiv; inactive/all explizit).
 */
class ModelProfileLifecycleExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
        Mail::fake();
    }

    private function userWithRole(UserRole $role, ?string $brand = 'rp'): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    /**
     * @return array{customer: Customer, profile: ModelProfile, age_proof: string, photos: array<int, string>}
     */
    private function modelWithFiles(int $monthsOld, string $brand = 'rp', ?User $portalUser = null, bool $withLastConfirmed = true): array
    {
        $customer = Customer::factory()->create([
            'brand' => $brand,
            'is_model' => true,
            'email' => 'model-'.bin2hex(random_bytes(4)).'@example.com',
            'user_id' => $portalUser?->id,
        ]);

        $store = app(ModelFileStore::class);
        $anchor = now()->subMonths($monthsOld)->subDay();

        $ageProof = "model-age-proofs/{$customer->id}/ausweis.jpg";
        $store->putEncrypted($ageProof, 'secret-id');

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $ageProof,
            'age_proof_uploaded_at' => $anchor,
            'submitted_at' => $anchor,
            'last_confirmed_at' => $withLastConfirmed ? $anchor : null,
        ]);

        $photoPaths = [];
        foreach (['a.jpg', 'b.jpg'] as $index => $name) {
            $path = "model-photos/{$customer->id}/{$name}";
            $store->putEncrypted($path, 'photo-'.$index);
            ModelPhoto::create([
                'customer_id' => $customer->id,
                'model_profile_id' => $profile->id,
                'path' => $path,
                'original_name' => $name,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 100,
                'visibility' => 'internal',
                'is_primary' => false,
                'position' => $index,
            ]);
            $photoPaths[] = $path;
        }

        ModelAccessToken::issueFor($customer);

        return ['customer' => $customer, 'profile' => $profile, 'age_proof' => $ageProof, 'photos' => $photoPaths];
    }

    public function test_expiry_run_hard_deletes_rows_files_and_audits(): void
    {
        Log::spy();

        $model = $this->modelWithFiles(15);
        $customer = $model['customer'];

        $act = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $customer->id,
            'act_type' => 'single',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $customer->id, 'role' => 'manager', 'position' => 0]);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('model_profiles', ['id' => $model['profile']->id]);
        $this->assertDatabaseMissing('model_photos', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('model_access_tokens', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('act_members', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('acts', ['id' => $act->id]);

        Storage::disk('local')->assertMissing($model['age_proof']);
        foreach ($model['photos'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        Log::shouldHaveReceived('info')
            ->with('model.profile.deleted', Mockery::on(
                fn (array $context) => $context['reason'] === 'expired' && $context['customer_id'] === $customer->id
            ))
            ->once();
    }

    public function test_active_profile_is_not_deleted_by_expiry_run(): void
    {
        $model = $this->modelWithFiles(14);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertDatabaseHas('customers', ['id' => $model['customer']->id]);
        Storage::disk('local')->assertExists($model['age_proof']);
    }

    public function test_expiry_uses_submitted_at_when_last_confirmed_at_is_null(): void
    {
        $model = $this->modelWithFiles(15, 'rp', null, withLastConfirmed: false);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['id' => $model['customer']->id]);
        $this->assertDatabaseMissing('model_profiles', ['id' => $model['profile']->id]);
        Storage::disk('local')->assertMissing($model['age_proof']);
    }

    public function test_reminder_uses_submitted_at_when_last_confirmed_at_is_null(): void
    {
        $model = $this->modelWithFiles(12, 'rp', null, withLastConfirmed: false);

        $this->artisan('app:process-model-lifecycle')->assertSuccessful();

        Mail::assertQueued(
            ModelProfileReminderMail::class,
            fn (ModelProfileReminderMail $mail) => $mail->hasTo($model['customer']->email) && $mail->stage === 't12'
        );
    }

    public function test_default_list_excludes_inactive_and_expired(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $active = $this->modelWithFiles(0);
        $inactive = $this->modelWithFiles(13);

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($active['profile']->id, $response->json('0.id'));
    }

    public function test_lifecycle_status_inactive_and_all_include_inactive(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $active = $this->modelWithFiles(0);
        $inactive = $this->modelWithFiles(13);

        $inactiveOnly = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?lifecycle_status=inactive');
        $inactiveOnly->assertOk()->assertJsonCount(1);
        $this->assertSame($inactive['profile']->id, $inactiveOnly->json('0.id'));

        $all = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?lifecycle_status=all');
        $all->assertOk()->assertJsonCount(2);

        // Search combines with the lifecycle filter for inactive profiles.
        $found = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?lifecycle_status=inactive&q=model-');
        $found->assertOk()->assertJsonCount(1);
        $this->assertSame($inactive['profile']->id, $found->json('0.id'));
    }

    public function test_super_admin_can_delete_inactive_profile(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, 'rp');
        $model = $this->modelWithFiles(13);

        $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/management/models/{$model['customer']->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('customers', ['id' => $model['customer']->id]);
        Storage::disk('local')->assertMissing($model['age_proof']);
    }
}
