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
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * DSGVO-Löschung von Model-Profilen: restlose Entfernung von Zeilen + Storage-
 * Dateien, verknüpften Registrierungs-Einladungen, Super-Admin-Gate, Brand-Scope
 * und der Customer-Observer als Generalschutz.
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

    public function test_super_admin_erasure_removes_linked_registration_invites_atomically(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, 'rp');
        $model = $this->modelWithFiles('rp');
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
        ActMember::create([
            'act_id' => $act->id,
            'customer_id' => $customer->id,
            'role' => 'manager',
            'position' => 0,
        ]);

        $inviter = User::factory()->create(['brand' => 'rp']);
        $linkedInvite = ModelRegistrationInvite::create([
            'token' => bin2hex(random_bytes(32)),
            'email' => 'private-invite@example.com',
            'label' => 'Private invitation label',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'used_at' => now(),
            'act_id' => $act->id,
            'customer_id' => $customer->id,
        ]);

        $unrelatedInvite = ModelRegistrationInvite::create([
            'token' => bin2hex(random_bytes(32)),
            'email' => 'other-invite@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/management/models/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('model_registration_invites', ['id' => $linkedInvite->id]);
        $this->assertDatabaseHas('model_registration_invites', [
            'id' => $unrelatedInvite->id,
            'customer_id' => null,
        ]);
    }

    public function test_erasure_rolls_back_customer_and_invite_cleanup_together(): void
    {
        $model = $this->modelWithFiles('rp');
        $customer = $model['customer'];
        $inviter = User::factory()->create(['brand' => 'rp']);
        $invite = ModelRegistrationInvite::create([
            'token' => bin2hex(random_bytes(32)),
            'email' => 'rollback-invite@example.com',
            'brand' => 'rp',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'customer_id' => $customer->id,
        ]);

        $failureTriggered = false;
        Customer::deleted(function () use (&$failureTriggered): void {
            $failureTriggered = true;
            throw new \RuntimeException('Simulated failure after customer delete');
        });

        $caught = null;
        try {
            app(ModelProfileEraser::class)->erase($customer, 'dsgvo');
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertTrue($failureTriggered);
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('Simulated failure after customer delete', $caught->getMessage());
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('model_registration_invites', ['id' => $invite->id]);
        Storage::disk('local')->assertExists($model['age_proof']);
        foreach ($model['photos'] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_deleting_manager_promotes_first_remaining_member(): void
    {
        $superAdmin = $this->userWithRole(UserRole::SUPER_ADMIN, 'rp');
        $model = $this->modelWithFiles('rp');
        $manager = $model['customer'];

        $act = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $manager->id,
            'act_type' => 'group',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 3,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $manager->id, 'role' => 'manager', 'position' => 0]);

        $second = Customer::factory()->create(['brand' => 'rp', 'name' => 'Zweite Person']);
        $third = Customer::factory()->create(['brand' => 'rp', 'name' => 'Dritte Person']);
        // Deliberately not in position order, to prove the lowest position wins.
        ActMember::create(['act_id' => $act->id, 'customer_id' => $third->id, 'role' => 'member', 'position' => 2]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $second->id, 'role' => 'member', 'position' => 1]);

        $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/management/models/{$manager->id}")
            ->assertOk();

        // The act survives with the first remaining member as its new manager.
        $this->assertDatabaseHas('acts', [
            'id' => $act->id,
            'manager_customer_id' => $second->id,
        ]);
        $this->assertDatabaseHas('act_members', [
            'act_id' => $act->id,
            'customer_id' => $second->id,
            'role' => 'manager',
        ]);
        $this->assertDatabaseHas('act_members', [
            'act_id' => $act->id,
            'customer_id' => $third->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseMissing('act_members', ['act_id' => $act->id, 'customer_id' => $manager->id]);
        $this->assertDatabaseMissing('customers', ['id' => $manager->id]);
    }

    public function test_direct_customer_delete_nulls_manager_but_keeps_act_and_members(): void
    {
        $model = $this->modelWithFiles('rp');
        $manager = $model['customer'];

        $act = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $manager->id,
            'act_type' => 'group',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 2,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $manager->id, 'role' => 'manager', 'position' => 0]);

        $member = Customer::factory()->create(['brand' => 'rp', 'name' => 'Restmitglied']);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $member->id, 'role' => 'member', 'position' => 1]);

        // Bypasses the eraser on purpose: the relaxed FK must not cascade the act.
        $manager->delete();

        $this->assertDatabaseHas('acts', ['id' => $act->id, 'manager_customer_id' => null]);
        $this->assertDatabaseHas('act_members', ['act_id' => $act->id, 'customer_id' => $member->id]);
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
