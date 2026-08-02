<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\PhotoJob;
use App\Enums\Brand;
use App\Enums\PhotoJobStatus;
use App\Support\BrandRegistry;

class PhotoJobBoardTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createPhotographer(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'photographer']);
        $user->roles()->attach($role);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_super_admin_sees_all_items(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);

        $otherUser = User::factory()->create();
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id]);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id]);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('photo_jobs'));
    }

    public function test_photographer_sees_only_own_and_assignee_items(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $otherUser = User::factory()->create();

        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id]);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id, 'assignee_id' => $photographer->id]);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('photo_jobs'));
    }

    public function test_store_sets_owner_and_initial_status_and_position(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $response = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Hochzeit Paar',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.owner_id', $photographer->id);
        $response->assertJsonPath('photo_job.status', PhotoJobStatus::SHOOTING->value);
        $response->assertJsonPath('photo_job.brand', Brand::B2B->value);
        $this->assertDatabaseHas('photo_jobs', [
            'owner_id' => $photographer->id,
            'status' => 'shooting',
            'position' => 0,
        ]);
    }

    public function test_move_changes_status_and_logs_workflow(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);

        $response = $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => PhotoJobStatus::CULLING->value,
            'position' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('photo_job.status', 'culling');
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'status' => 'culling']);
        $this->assertDatabaseHas('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
            'from_status' => 'shooting',
            'to_status' => 'culling',
            'user_id' => $photographer->id,
        ]);
    }

    public function test_move_with_position_only_logs_not_workflow(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'shooting',
            'position' => 5,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
        ]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'position' => 5]);
    }

    public function test_admin_cannot_access_photo_jobs(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/management/photo-jobs')->assertStatus(403);
    }

    public function test_brand_isolation(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);

        PhotoJob::factory()->create(['brand' => Brand::B2B]);
        PhotoJob::factory()->create(['brand' => 'othr']);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('photo_jobs'));
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/management/photo-jobs')->assertStatus(401);
    }
}