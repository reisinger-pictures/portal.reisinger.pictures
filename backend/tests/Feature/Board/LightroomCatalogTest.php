<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\LightroomCatalog;

class LightroomCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->roles()->attach($role);
        return $user;
    }

    private function createSuperAdmin(): User
    {
        return $this->createUserWithRole('super_admin');
    }

    private function createAdmin(): User
    {
        return $this->createUserWithRole('admin');
    }

    private function createPhotographer(): User
    {
        return $this->createUserWithRole('photographer');
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_photographer_can_crud_own_catalog(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $create = $this->withHeaders($headers)->postJson('/api/management/lightroom-catalogs', [
            'name' => '2026-08',
        ]);
        $create->assertStatus(201);
        $create->assertJsonPath('lightroom_catalog.name', '2026-08');
        $create->assertJsonPath('lightroom_catalog.user_id', $photographer->id);
        $create->assertJsonPath('lightroom_catalog.position', 0);

        $id = $create->json('lightroom_catalog.id');

        $this->withHeaders($headers)->putJson("/api/management/lightroom-catalogs/{$id}", [
            'name' => '2026-09',
        ])->assertStatus(200);
        $this->assertDatabaseHas('lightroom_catalogs', ['id' => $id, 'name' => '2026-09']);

        $this->withHeaders($headers)->deleteJson("/api/management/lightroom-catalogs/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('lightroom_catalogs', ['id' => $id]);
    }

    public function test_photographer_can_get_own_catalog_list(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-07']);
        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-08']);

        $response = $this->withHeaders($headers)->getJson('/api/management/lightroom-catalogs');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('lightroom_catalogs'));
    }

    public function test_photographer_a_does_not_see_or_modify_photographer_b_catalogs(): void
    {
        $photographerA = $this->createPhotographer();
        $photographerB = $this->createPhotographer();
        $headersA = $this->authHeaders($photographerA);

        LightroomCatalog::factory()->create(['user_id' => $photographerA->id, 'name' => '2026-08']);
        $bCatalog = LightroomCatalog::factory()->create(['user_id' => $photographerB->id, 'name' => '2026-07']);

        $response = $this->withHeaders($headersA)->getJson('/api/management/lightroom-catalogs');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('lightroom_catalogs'));
        $this->assertSame('2026-08', $response->json('lightroom_catalogs.0.name'));

        $this->withHeaders($headersA)->putJson("/api/management/lightroom-catalogs/{$bCatalog->id}", [
            'name' => 'hijack',
        ])->assertStatus(404);
        $this->withHeaders($headersA)->deleteJson("/api/management/lightroom-catalogs/{$bCatalog->id}")->assertStatus(404);
        $this->assertDatabaseHas('lightroom_catalogs', ['id' => $bCatalog->id, 'name' => '2026-07']);
    }

    public function test_super_admin_manages_only_own_catalogs(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $otherUser = User::factory()->create();
        $headers = $this->authHeaders($superAdmin);

        LightroomCatalog::factory()->create(['user_id' => $superAdmin->id, 'name' => '2026-08']);
        LightroomCatalog::factory()->create(['user_id' => $otherUser->id, 'name' => '2026-07']);

        $response = $this->withHeaders($headers)->getJson('/api/management/lightroom-catalogs');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('lightroom_catalogs'));
        $this->assertSame('2026-08', $response->json('lightroom_catalogs.0.name'));

        $otherCatalog = LightroomCatalog::where('user_id', $otherUser->id)->first();
        $this->withHeaders($headers)->putJson("/api/management/lightroom-catalogs/{$otherCatalog->id}", [
            'name' => 'hijack',
        ])->assertStatus(404);
        $this->withHeaders($headers)->deleteJson("/api/management/lightroom-catalogs/{$otherCatalog->id}")->assertStatus(404);
    }

    public function test_admin_gets_403_on_all_endpoints(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/management/lightroom-catalogs')->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/management/lightroom-catalogs', ['name' => 'X'])->assertStatus(403);

        $otherCatalog = LightroomCatalog::factory()->create();
        $this->withHeaders($headers)->putJson("/api/management/lightroom-catalogs/{$otherCatalog->id}", ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($headers)->deleteJson("/api/management/lightroom-catalogs/{$otherCatalog->id}")->assertStatus(403);
    }

    public function test_position_auto_increments_per_user(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $this->withHeaders($headers)->postJson('/api/management/lightroom-catalogs', ['name' => '2026-07'])->assertStatus(201);
        $this->withHeaders($headers)->postJson('/api/management/lightroom-catalogs', ['name' => '2026-08'])->assertStatus(201);

        $this->assertDatabaseHas('lightroom_catalogs', ['user_id' => $photographer->id, 'name' => '2026-07', 'position' => 0]);
        $this->assertDatabaseHas('lightroom_catalogs', ['user_id' => $photographer->id, 'name' => '2026-08', 'position' => 1]);
    }

    public function test_position_increments_independently_per_user(): void
    {
        $photographerA = $this->createPhotographer();
        $photographerB = $this->createPhotographer();

        $this->withHeaders($this->authHeaders($photographerA))->postJson('/api/management/lightroom-catalogs', ['name' => '2026-06'])->assertStatus(201);

        $this->withHeaders($this->authHeaders($photographerB))->postJson('/api/management/lightroom-catalogs', ['name' => '2026-07'])->assertStatus(201);
        $this->withHeaders($this->authHeaders($photographerB))->postJson('/api/management/lightroom-catalogs', ['name' => '2026-08'])->assertStatus(201);

        $this->assertDatabaseHas('lightroom_catalogs', ['user_id' => $photographerA->id, 'name' => '2026-06', 'position' => 0]);
        $this->assertDatabaseHas('lightroom_catalogs', ['user_id' => $photographerB->id, 'name' => '2026-07', 'position' => 0]);
        $this->assertDatabaseHas('lightroom_catalogs', ['user_id' => $photographerB->id, 'name' => '2026-08', 'position' => 1]);
    }

    public function test_duplicate_name_for_same_user_rejected_with_422(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-08']);

        $this->withHeaders($headers)->postJson('/api/management/lightroom-catalogs', [
            'name' => '2026-08',
        ])->assertStatus(422);
    }

    public function test_same_name_allowed_for_different_users(): void
    {
        $photographerA = $this->createPhotographer();
        $photographerB = $this->createPhotographer();

        LightroomCatalog::factory()->create(['user_id' => $photographerA->id, 'name' => '2026-08']);

        $this->withHeaders($this->authHeaders($photographerB))->postJson('/api/management/lightroom-catalogs', [
            'name' => '2026-08',
        ])->assertStatus(201);
    }

    public function test_update_cannot_rename_to_duplicate_for_same_user(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-07']);
        $catalog = LightroomCatalog::factory()->create(['user_id' => $photographer->id, 'name' => '2026-08']);

        $this->withHeaders($headers)->putJson("/api/management/lightroom-catalogs/{$catalog->id}", [
            'name' => '2026-07',
        ])->assertStatus(422);
        $this->assertDatabaseHas('lightroom_catalogs', ['id' => $catalog->id, 'name' => '2026-08']);
    }

    public function test_unauthenticated_gets_401_on_all_endpoints(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        $this->getJson('/api/management/lightroom-catalogs')->assertStatus(401);
        $this->postJson('/api/management/lightroom-catalogs', ['name' => 'X'])->assertStatus(401);
        $this->putJson("/api/management/lightroom-catalogs/{$id}", ['name' => 'X'])->assertStatus(401);
        $this->deleteJson("/api/management/lightroom-catalogs/{$id}")->assertStatus(401);
    }
}
