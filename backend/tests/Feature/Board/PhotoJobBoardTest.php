<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\PhotoJob;
use App\Models\LightroomCatalog;
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

    public function test_store_persists_notes(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $response = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Hochzeit Paar',
            'notes' => 'Erste interne Notiz',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.notes', 'Erste interne Notiz');
        $this->assertDatabaseHas('photo_jobs', [
            'title' => 'Hochzeit Paar',
            'notes' => 'Erste interne Notiz',
        ]);
    }

    public function test_update_overwrites_and_clears_notes(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'notes' => 'Alte Notiz',
        ]);

        $overwrite = $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$photoJob->id}", [
            'notes' => 'Neue Notiz',
        ]);
        $overwrite->assertStatus(200);
        $overwrite->assertJsonPath('photo_job.notes', 'Neue Notiz');
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'notes' => 'Neue Notiz']);

        $clear = $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$photoJob->id}", [
            'notes' => null,
        ]);
        $clear->assertStatus(200);
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'notes' => null]);
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
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'position' => 0]);
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

    public function test_update_changes_fields_and_keeps_owner_without_workflow_log(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'total_count' => 0]);

        $response = $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$photoJob->id}", [
            'title' => 'Neuer Titel',
            'total_count' => 1200,
            'lightroom_catalog' => '2026-08',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('photo_job.title', 'Neuer Titel');
        $response->assertJsonPath('photo_job.total_count', 1200);
        $response->assertJsonPath('photo_job.owner_id', $photographer->id);
        $this->assertDatabaseHas('photo_jobs', [
            'id' => $photoJob->id,
            'title' => 'Neuer Titel',
            'total_count' => 1200,
            'owner_id' => $photographer->id,
        ]);
        $this->assertDatabaseMissing('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
        ]);
    }

    public function test_admin_cannot_update_photo_jobs(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B]);

        $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$photoJob->id}", [
            'title' => 'Hijack',
        ])->assertStatus(403);
    }

    public function test_destroy_removes_item_but_keeps_workflow_log(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting']);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'culling',
            'position' => 0,
        ])->assertStatus(200);

        $this->withHeaders($headers)->deleteJson("/api/management/photo-jobs/{$photoJob->id}")->assertStatus(200);

        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
        $this->assertDatabaseHas('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
            'to_status' => 'culling',
        ]);
    }

    public function test_assignee_can_move_item_owned_by_other_user(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $otherUser = User::factory()->create();
        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $otherUser->id,
            'assignee_id' => $photographer->id,
            'status' => 'shooting',
        ]);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'culling',
            'position' => 0,
        ])->assertStatus(200);

        $this->assertDatabaseHas('photo_jobs', [
            'id' => $photoJob->id,
            'status' => 'culling',
            'owner_id' => $otherUser->id,
        ]);
    }

    public function test_move_to_abgebrochen_is_accepted_and_logged(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);

        $response = $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'abgebrochen',
            'position' => 3,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('photo_job.status', 'abgebrochen');
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'status' => 'abgebrochen']);
        $this->assertDatabaseHas('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
            'from_status' => 'shooting',
            'to_status' => 'abgebrochen',
            'user_id' => $photographer->id,
        ]);
    }

    public function test_move_rejects_invalid_status_with_422(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'garbage',
            'position' => 0,
        ])->assertStatus(422);
    }

    public function test_unauthenticated_gets_401_on_all_endpoints(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        $this->getJson('/api/management/photo-jobs')->assertStatus(401);
        $this->postJson('/api/management/photo-jobs', ['title' => 'X'])->assertStatus(401);
        $this->putJson("/api/management/photo-jobs/{$id}", ['title' => 'X'])->assertStatus(401);
        $this->patchJson("/api/management/photo-jobs/{$id}/move", ['status' => 'shooting', 'position' => 0])->assertStatus(401);
        $this->deleteJson("/api/management/photo-jobs/{$id}")->assertStatus(401);
    }

    public function test_store_accepts_custom_status_and_positions_per_status_column(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $first = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Job A',
            'status' => PhotoJobStatus::CULLING->value,
        ]);
        $first->assertStatus(201);
        $first->assertJsonPath('photo_job.status', 'culling');
        $first->assertJsonPath('photo_job.position', 0);

        $second = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Job B',
            'status' => PhotoJobStatus::CULLING->value,
        ]);
        $second->assertStatus(201);
        $second->assertJsonPath('photo_job.status', 'culling');
        $second->assertJsonPath('photo_job.position', 1);

        $other = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Job C',
            'status' => PhotoJobStatus::SHOOTING->value,
        ]);
        $other->assertStatus(201);
        $other->assertJsonPath('photo_job.status', 'shooting');
        $other->assertJsonPath('photo_job.position', 0);
    }

    public function test_store_defaults_to_initial_status_without_status(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $response = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Hochzeit Paar',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.status', PhotoJobStatus::initial()->value);
        $response->assertJsonPath('photo_job.position', 0);
    }

    public function test_move_reindexes_both_columns_densely_and_stably(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $j1 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 0]);
        $j2 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 0]);
        $j3 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 5]);
        $j4 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'culling', 'position' => 0]);
        $j5 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'culling', 'position' => 0]);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$j4->id}/move", [
            'status' => 'shooting',
            'position' => 1,
        ])->assertStatus(200);

        $first = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $first->assertStatus(200);
        $second = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $second->assertStatus(200);

        $this->assertColumnDense($first->json('photo_jobs'), 'shooting');
        $this->assertColumnDense($first->json('photo_jobs'), 'culling');
        $this->assertSame($first->json('photo_jobs'), $second->json('photo_jobs'));
    }

    public function test_move_to_end_with_large_position_creates_no_holes(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 0]);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 1]);
        $target = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 2]);

        $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$target->id}/move", [
            'status' => 'shooting',
            'position' => 99,
        ])->assertStatus(200);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);

        $this->assertColumnDense($response->json('photo_jobs'), 'shooting');
        $targetPosition = collect($response->json('photo_jobs'))->firstWhere('id', $target->id)['position'];
        $this->assertSame(2, $targetPosition);
    }

    public function test_update_accepts_status_without_workflow_log(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id]);

        $response = $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$photoJob->id}", [
            'status' => 'export',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('photo_job.status', 'export');
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id, 'status' => 'export']);
        $this->assertDatabaseMissing('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $photoJob->id,
        ]);
    }

    public function test_update_status_change_reindexes_both_columns_densely(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $j1 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 0]);
        $j2 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 0]);
        $j3 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'shooting', 'position' => 5]);
        $j4 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'culling', 'position' => 0]);
        $j5 = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'status' => 'culling', 'position' => 0]);

        $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$j2->id}", [
            'status' => 'culling',
        ])->assertStatus(200);

        $first = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $first->assertStatus(200);
        $second = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $second->assertStatus(200);

        $this->assertColumnDense($first->json('photo_jobs'), 'shooting');
        $this->assertColumnDense($first->json('photo_jobs'), 'culling');
        $this->assertSame($first->json('photo_jobs'), $second->json('photo_jobs'));

        $cullingColumn = array_values(array_filter($first->json('photo_jobs'), fn ($photoJob) => $photoJob['status'] === 'culling'));
        $this->assertSame($j2->id, end($cullingColumn)['id']);
        $this->assertSame(count($cullingColumn) - 1, end($cullingColumn)['position']);
        $this->assertDatabaseMissing('workflow_logs', [
            'item_type' => 'photo_job',
            'item_id' => $j2->id,
        ]);
    }

    private function assertColumnDense(array $photoJobs, string $status): void
    {
        $column = array_values(array_filter($photoJobs, fn ($photoJob) => $photoJob['status'] === $status));
        $positions = array_map(fn ($photoJob) => $photoJob['position'], $column);
        $this->assertSame(range(0, count($positions) - 1), $positions);
    }

    public function test_index_flags_catalog_as_mine_when_in_viewers_own_catalogs(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);

        LightroomCatalog::factory()->create(['user_id' => $superAdmin->id, 'name' => '2026-08']);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id, 'lightroom_catalog' => '2026-08']);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertTrue($response->json('photo_jobs.0.lightroom_catalog_is_mine'));
        $this->assertSame('2026-08', $response->json('photo_jobs.0.lightroom_catalog'));
    }

    public function test_index_flags_catalog_as_not_mine_when_foreign(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-07']);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'lightroom_catalog' => '2026-08']);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertFalse($response->json('photo_jobs.0.lightroom_catalog_is_mine'));
    }

    public function test_super_admin_without_own_catalogs_gets_flag_false_and_raw_name_kept(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        $otherUser = User::factory()->create();

        LightroomCatalog::factory()->create(['user_id' => $otherUser->id, 'name' => '2026-08']);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id, 'lightroom_catalog' => '2026-08']);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);

        $this->assertFalse($response->json('photo_jobs.0.lightroom_catalog_is_mine'));
        $this->assertSame('2026-08', $response->json('photo_jobs.0.lightroom_catalog'));
    }

    public function test_index_flags_null_catalog_as_not_mine(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $photographer->id, 'lightroom_catalog' => null]);

        $response = $this->withHeaders($headers)->getJson('/api/management/photo-jobs');
        $response->assertStatus(200);
        $this->assertFalse($response->json('photo_jobs.0.lightroom_catalog_is_mine'));
    }

    public function test_store_and_update_set_privacy_flag_on_single_job(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-08']);

        $store = $this->withHeaders($headers)->postJson('/api/management/photo-jobs', [
            'title' => 'Hochzeit Paar',
            'lightroom_catalog' => '2026-08',
        ]);
        $store->assertStatus(201);
        $store->assertJsonPath('photo_job.lightroom_catalog_is_mine', true);

        $id = $store->json('photo_job.id');

        $update = $this->withHeaders($headers)->putJson("/api/management/photo-jobs/{$id}", [
            'lightroom_catalog' => 'fremder-katalog',
        ]);
        $update->assertStatus(200);
        $update->assertJsonPath('photo_job.lightroom_catalog_is_mine', false);
        $update->assertJsonPath('photo_job.lightroom_catalog', 'fremder-katalog');
    }

    public function test_move_sets_privacy_flag_on_single_job(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'lightroom_catalog' => '2026-08',
        ]);

        $response = $this->withHeaders($headers)->patchJson("/api/management/photo-jobs/{$photoJob->id}/move", [
            'status' => 'culling',
            'position' => 0,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('photo_job.lightroom_catalog_is_mine'));
    }
}