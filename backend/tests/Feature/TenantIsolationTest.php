<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_manager_cannot_update_or_delete_user_from_other_tenant()
    {
        $roleManager = Role::firstOrCreate(['name' => 'customer_manager']);
        
        $tenantA = Tenant::create(['name' => 'Tenant A', 'invoice_frequency' => 'immediate']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'invoice_frequency' => 'immediate']);

        $managerA = User::factory()->create();
        $managerA->roles()->attach($roleManager);
        $managerA->tenants()->attach($tenantA);

        $userB = User::factory()->create();
        $userB->tenants()->attach($tenantB);

        $token = auth('api')->login($managerA);

        // Flow AI: Update attempt on cross-tenant user -> Expect 403
        $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->putJson("/api/management/users/{$userB->id}", [
                 'role_ids' => [],
                 'gallery_group_ids' => [],
                 'gallery_ids' => [],
                 'can_edit_metadata' => false
             ])
             ->assertStatus(403);

        // Flow AI: Delete attempt on cross-tenant user -> Expect 403
        $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->deleteJson("/api/management/users/{$userB->id}")
             ->assertStatus(403);
             
        // Flow S & U: Scope attempt -> Must not see users from Tenant B
        $res = $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->getJson("/api/management/users");
             
        $res->assertStatus(200);
        $usersReturned = collect($res->json('data'));
        $this->assertEmpty($usersReturned->where('id', $userB->id));
    }
}
