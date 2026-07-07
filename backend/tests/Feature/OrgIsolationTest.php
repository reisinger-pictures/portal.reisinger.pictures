<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admin_cannot_update_or_delete_user_from_other_org()
    {
        $roleManager = Role::firstOrCreate(['name' => \App\Enums\UserRole::ORG_ADMIN->value]);
        
        $orgA = Org::create(['name' => 'Org A', 'invoice_frequency' => 'immediate']);
        $orgB = Org::create(['name' => 'Org B', 'invoice_frequency' => 'immediate']);

        $managerA = User::factory()->create();
        $managerA->roles()->attach($roleManager);
        $managerA->org_id = $orgA->id;
        $managerA->save();

        $userB = User::factory()->create();
        $userB->org_id = $orgB->id;
        $userB->save();

        $token = auth('api')->login($managerA);

        // Flow AI: Update attempt on cross-org user -> Expect 403
        $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->putJson("/api/management/users/{$userB->id}", [
                 'role_ids' => [],
                 'gallery_group_ids' => [],
                 'gallery_ids' => [],
                 'can_edit_metadata' => false,
                 'brand' => 'rp'
             ])
             ->assertStatus(403);

        // Flow AI: Delete attempt on cross-org user -> Expect 403
        $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->deleteJson("/api/management/users/{$userB->id}")
             ->assertStatus(403);
             
        // Flow S & U: Scope attempt -> Must not see users from Org B
        $res = $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->getJson("/api/management/users");
             
        $res->assertStatus(200);
        $usersReturned = collect($res->json('data'));
        $this->assertEmpty($usersReturned->where('id', $userB->id));
    }

    public function test_org_admin_can_only_see_own_orgs() {
        $roleManager = Role::firstOrCreate(['name' => 'org_admin']);
        
        $orgA = Org::create(['name' => 'Org A', 'invoice_frequency' => 'immediate']);
        $orgB = Org::create(['name' => 'Org B', 'invoice_frequency' => 'immediate']);

        $managerA = User::factory()->create();
        $managerA->roles()->attach($roleManager);
        $managerA->org_id = $orgA->id;
        $managerA->save();

        $token = auth('api')->login($managerA);

        $res = $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->getJson("/api/management/orgs");
             
        $res->assertStatus(200);
        $orgsReturned = collect($res->json());
        $this->assertNotEmpty($orgsReturned->where('id', $orgA->id));
        $this->assertEmpty($orgsReturned->where('id', $orgB->id));
    }


    public function test_user_is_auto_joined_to_org_based_on_domain()
    {
        $org = Org::create(['name' => 'B2B Corp', 'domain' => 'b2b-corp.com', 'invoice_frequency' => 'monthly']);

        // Auto-join is deferred: register should NOT attach org_id
        $res = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john.doe@b2b-corp.com'
        ]);

        $res->assertStatus(200);

        $user = User::where('email', 'john.doe@b2b-corp.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->org_id, 'User should NOT be joined to org before password reset');

        // Simulate password reset (proves email ownership) → triggers deferred auto-join
        // Replace the existing token (created during register) with a known one
        $rawToken = 'test-auto-join-token';
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', 'john.doe@b2b-corp.com')->delete();
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email' => 'john.doe@b2b-corp.com',
            'token' => \Illuminate\Support\Facades\Hash::make($rawToken),
            'created_at' => now(),
        ]);

        $resetRes = $this->postJson('/api/auth/reset-password', [
            'email' => 'john.doe@b2b-corp.com',
            'token' => $rawToken,
            'password' => 'newPassword123',
        ]);
        $resetRes->assertStatus(200);

        $user->refresh();

        // Prüfen, ob der User nach Passwort-Reset an die Org gebunden wurde
        $this->assertEquals($org->id, $user->org_id);

        // Prüfen, ob er die Client-Rolle bekommen hat
        $this->assertTrue($user->roles->contains('name', \App\Enums\UserRole::CLIENT->value));
    }

    public function test_deferred_auto_join_with_subdomain_email()
    {
        $org = Org::create(['name' => 'Subdomain Corp', 'domain' => 'sub.b2b-corp.com', 'invoice_frequency' => 'monthly']);

        // Register with subdomain email (multi-level domain)
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@sub.b2b-corp.com'
        ]);
        $res->assertStatus(200);

        $user = User::where('email', 'jane.doe@sub.b2b-corp.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->org_id, 'Should not be joined before password reset');

        // Simulate password reset
        $rawToken = 'test-subdomain-token';
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', 'jane.doe@sub.b2b-corp.com')->delete();
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email' => 'jane.doe@sub.b2b-corp.com',
            'token' => \Illuminate\Support\Facades\Hash::make($rawToken),
            'created_at' => now(),
        ]);

        $resetRes = $this->postJson('/api/auth/reset-password', [
            'email' => 'jane.doe@sub.b2b-corp.com',
            'token' => $rawToken,
            'password' => 'newPassword456',
        ]);
        $resetRes->assertStatus(200);

        $user->refresh();
        $this->assertEquals($org->id, $user->org_id);
        $this->assertTrue($user->roles->contains('name', \App\Enums\UserRole::CLIENT->value));
    }

    public function test_org_admin_cannot_view_other_org_details()
    {
        $roleManager = Role::firstOrCreate(['name' => 'org_admin']);
        
        $orgA = Org::create(['name' => 'Org A', 'invoice_frequency' => 'immediate']);
        $orgB = Org::create(['name' => 'Org B', 'invoice_frequency' => 'immediate']);

        $managerA = User::factory()->create();
        $managerA->roles()->attach($roleManager);
        $managerA->org_id = $orgA->id;
        $managerA->save();

        $token = auth('api')->login($managerA);

        // Versuch, Org B abzurufen
        $response = $this->withHeaders(['Authorization' => "Bearer " . $token])
             ->getJson("/api/management/orgs/{$orgB->id}");
             
        $response->assertStatus(403);
    }
}
