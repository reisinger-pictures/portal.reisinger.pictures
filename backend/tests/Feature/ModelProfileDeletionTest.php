<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * DSGVO-Löschung von Model-Profilen: restlose Entfernung von Zeilen + Storage-
 * Dateien, Super-Admin-Gate, Brand-Scope und der Customer-Observer als
 * Generalschutz.
 */
class ModelProfileDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
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
    private function modelWithFiles(string $brand = 'rp', ?User $portalUser = null): array
    {
        $customer = Customer::factory()->create([
            'brand' => $brand,
            'is_model' => true,
            'email' => 'model-'.bin2hex(random_bytes(4)).'@example.com',
            'user_id' => $portalUser?->id,
        ]);

        $store = app(ModelFileStore::class);

        $ageProof = "model-age-proofs/{$customer->id}/ausweis.jpg";
        $store->putEncrypted($ageProof, 'secret-id');

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'age_proof_required' => true,
            'age_proof_path' => $ageProof,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
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
                'is_primary' => $index === 0,
                'position' => $index,
            ]);
            $photoPaths[] = $path;
        }

        ModelAccessToken::issueFor($customer);

        return ['customer' => $customer, 'profile' => $profile, 'age_proof' => $ageProof, 'photos' => $photoPaths];
    }

    public function test_super_admin_can_erase_model_profile_completely(): void
    {
        Log::spy();

        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, 'rp');
        $portalUser = User::factory()->create(['brand' => 'rp']);
        $model = $this->modelWithFiles('rp', $portalUser);
        $customer = $model['customer'];

        // Act where the model is the manager → cascade-deleted with the customer.
        $managerAct = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $customer->id,
            'act_type' => 'single',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $managerAct->id, 'customer_id' => $customer->id, 'role' => 'manager', 'position' => 0]);

        // Act whose only member is the model (managed by someone else) → becomes
        // memberless after the deletion and must be removed too.
        $otherManager = Customer::factory()->create(['brand' => 'rp']);
        $orphanAct = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $otherManager->id,
            'act_type' => 'single',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 1,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $orphanAct->id, 'customer_id' => $customer->id, 'role' => 'member', 'position' => 0]);

        $response = $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/management/models/{$customer->id}");

        $response->assertOk()->assertJsonPath('success', true);

        // Rows gone.
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('model_profiles', ['id' => $model['profile']->id]);
        $this->assertDatabaseMissing('model_photos', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('model_access_tokens', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('act_members', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('acts', ['id' => $managerAct->id]);
        $this->assertDatabaseMissing('acts', ['id' => $orphanAct->id]);

        // Files gone from the private disk.
        Storage::disk('local')->assertMissing($model['age_proof']);
        foreach ($model['photos'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        // Portal account only unlinked, never deleted.
        $this->assertNotNull($portalUser->fresh());

        // Audit log without PII.
        Log::shouldHaveReceived('info')
            ->with('model.profile.deleted', Mockery::on(function (array $context) use ($customer): bool {
                return $context['customer_id'] === $customer->id
                    && $context['photo_count'] === 2
                    && $context['had_age_proof'] === true
                    && ! array_key_exists('name', $context)
                    && ! array_key_exists('email', $context);
            }))
            ->once();
    }

    public function test_admin_cannot_erase_model_profile(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $model = $this->modelWithFiles('rp');

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/management/models/{$model['customer']->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('customers', ['id' => $model['customer']->id]);
        Storage::disk('local')->assertExists($model['age_proof']);
    }

    public function test_foreign_brand_model_is_not_found(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, 'rp');
        $model = $this->modelWithFiles('srp');

        $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/management/models/{$model['customer']->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $model['customer']->id]);
        Storage::disk('local')->assertExists($model['age_proof']);
    }

    public function test_observer_removes_files_on_direct_customer_delete(): void
    {
        $model = $this->modelWithFiles('rp');

        $model['customer']->delete();

        $this->assertDatabaseMissing('customers', ['id' => $model['customer']->id]);
        $this->assertDatabaseMissing('model_photos', ['customer_id' => $model['customer']->id]);
        Storage::disk('local')->assertMissing($model['age_proof']);
        foreach ($model['photos'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }
}
