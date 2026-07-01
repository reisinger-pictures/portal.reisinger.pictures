<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserPermissionUpdateTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_update_user_permissions_and_sync_relations() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $targetUser = User::factory()->create();
        
        $role = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/management/users/{$targetUser->id}", [
                             'role_ids' => [$role->id],
                             'gallery_group_ids' => [$group->id],
                             'gallery_ids' => [$gallery->id],
                             'can_edit_metadata' => true,
                             'brand' => 'rp',
                         ]);

        $response->assertStatus(200);

        // Prüfe ob die Pivot-Tabellen korrekt synchronisiert wurden
        $this->assertDatabaseHas('user_roles', ['user_id' => $targetUser->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('user_gallery_groups', ['user_id' => $targetUser->id, 'gallery_group_id' => $group->id]);
        $this->assertDatabaseHas('user_galleries', ['user_id' => $targetUser->id, 'gallery_id' => $gallery->id]);
        
        // Prüfe direktes Property
        $this->assertDatabaseHas('users', ['id' => $targetUser->id, 'can_edit_metadata' => 1]);
    }

    public function test_non_super_admin_must_have_brand_set() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $targetUser = User::factory()->create();
        $role = Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]);

        // Attempt to update without brand — must fail validation under U-02.
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/management/users/{$targetUser->id}", [
                             'role_ids' => [$role->id],
                             'brand' => null,
                         ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('brand');
    }

    public function test_super_admin_is_forced_to_null_brand() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($admin);

        $targetUser = User::factory()->create();
        $superAdminRole = Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]);

        // Setting brand='rp' with super_admin role must be rejected or forced to null.
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/management/users/{$targetUser->id}", [
                             'role_ids' => [$superAdminRole->id],
                             'brand' => 'rp',
                         ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('brand');
    }

    public function test_super_admin_update_with_null_brand_succeeds() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($admin);

        $targetUser = User::factory()->create();
        $superAdminRole = Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/management/users/{$targetUser->id}", [
                             'role_ids' => [$superAdminRole->id],
                             'brand' => null,
                         ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $targetUser->id, 'brand' => null]);
    }
}