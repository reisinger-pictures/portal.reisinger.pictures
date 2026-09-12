<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: P1 finding — org_admins must not be able to escalate a user
 * (including themselves) to a role above their own privilege level.
 */
class UserPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function assignRole(User $user, UserRole $role): Role
    {
        $roleModel = Role::firstOrCreate(['name' => $role->value]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);

        return $roleModel;
    }

    private function makeOrgAdmin(Org $org): User
    {
        $user = User::factory()->create(['org_id' => $org->id, 'brand' => 'rp']);
        $this->assignRole($user, UserRole::ORG_ADMIN);

        return $user;
    }

    public function test_org_admin_cannot_escalate_user_to_admin(): void
    {
        $org = Org::factory()->create();
        $orgAdmin = $this->makeOrgAdmin($org);
        $target = User::factory()->create(['org_id' => $org->id, 'brand' => 'rp']);

        $adminRole = Role::firstOrCreate(['name' => UserRole::ADMIN->value]);
        $token = auth('api')->login($orgAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$adminRole->id],
                'brand' => 'rp',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $target->id,
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_org_admin_cannot_escalate_themselves_to_admin(): void
    {
        $org = Org::factory()->create();
        $orgAdmin = $this->makeOrgAdmin($org);

        $adminRole = Role::firstOrCreate(['name' => UserRole::ADMIN->value]);
        $token = auth('api')->login($orgAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$orgAdmin->id}", [
                'role_ids' => [$adminRole->id],
                'brand' => 'rp',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $orgAdmin->id,
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_org_admin_can_assign_roles_at_or_below_own_level(): void
    {
        $org = Org::factory()->create();
        $orgAdmin = $this->makeOrgAdmin($org);
        $target = User::factory()->create(['org_id' => $org->id, 'brand' => 'rp']);

        $clientRole = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
        $token = auth('api')->login($orgAdmin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$clientRole->id],
                'brand' => 'rp',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $clientRole->id,
        ]);
    }

    public function test_admin_can_still_assign_admin_role(): void
    {
        $admin = User::factory()->create(['brand' => 'rp']);
        $this->assignRole($admin, UserRole::ADMIN);
        $target = User::factory()->create(['brand' => 'rp']);

        $adminRole = Role::firstOrCreate(['name' => UserRole::ADMIN->value]);
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/users/{$target->id}", [
                'role_ids' => [$adminRole->id],
                'brand' => 'rp',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $adminRole->id,
        ]);
    }
}
