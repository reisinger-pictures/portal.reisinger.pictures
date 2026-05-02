<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserPermissionUpdateTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_update_user_permissions_and_sync_relations() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $targetUser = User::factory()->create();
        
        $role = Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]);
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->putJson("/api/management/users/{$targetUser->id}", [
                             'role_ids' => [$role->id],
                             'gallery_group_ids' => [$group->id],
                             'gallery_ids' => [$gallery->id],
                             'can_edit_metadata' => true
                         ]);

        $response->assertStatus(200);

        // Prüfe ob die Pivot-Tabellen korrekt synchronisiert wurden
        $this->assertDatabaseHas('user_roles', ['user_id' => $targetUser->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('user_gallery_groups', ['user_id' => $targetUser->id, 'gallery_group_id' => $group->id]);
        $this->assertDatabaseHas('user_galleries', ['user_id' => $targetUser->id, 'gallery_id' => $gallery->id]);
        
        // Prüfe direktes Property
        $this->assertDatabaseHas('users', ['id' => $targetUser->id, 'can_edit_metadata' => 1]);
    }
}