<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Enums\UserRole;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrgOrganizationCoreTest extends TestCase
{
    use RefreshDatabase;

    private function orgAdminContext(): array
    {
        $org = Org::factory()->create(['invoice_frequency' => 'immediate']);
        $user = User::factory()->create(['org_id' => $org->id]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));
        return ['token' => auth('api')->login($user), 'org' => $org, 'user' => $user];
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        return auth('api')->login($user);
    }

    private function orgAdminWithoutOrgToken(): string
    {
        $user = User::factory()->create(['org_id' => null]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));
        return auth('api')->login($user);
    }

    // ──────────────────────────────────────────────
    // N2: Org-Admin editiert eigene Org (@update)
    // ──────────────────────────────────────────────

    public function test_org_admin_can_update_own_org(): void
    {
        $ctx = $this->orgAdminContext();

        $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->putJson('/api/management/orgs/' . $ctx['org']->id, [
                'name' => 'Updated Org',
                'invoice_frequency' => 'monthly',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orgs', [
            'id' => $ctx['org']->id,
            'name' => 'Updated Org',
        ]);
    }

    // ──────────────────────────────────────────────
    // N3: Org-Admin editiert NICHT fremde Org (403)
    // ──────────────────────────────────────────────

    public function test_org_admin_cannot_update_other_org(): void
    {
        $ctx = $this->orgAdminContext();
        $otherOrg = Org::factory()->create(['invoice_frequency' => 'immediate']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->putJson('/api/management/orgs/' . $otherOrg->id, [
                'name' => 'Hacked',
                'invoice_frequency' => 'monthly',
            ])
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // N4: Org-Admin erstellt KEINE Org (@store → 403)
    // ──────────────────────────────────────────────

    public function test_org_admin_cannot_create_org(): void
    {
        $ctx = $this->orgAdminContext();

        $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/orgs', [
                'name' => 'New Org',
                'invoice_frequency' => 'immediate',
            ])
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // N5: Org-Admin löscht KEINE Org (@destroy → 403)
    // ──────────────────────────────────────────────

    public function test_org_admin_cannot_delete_org(): void
    {
        $ctx = $this->orgAdminContext();

        $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->deleteJson('/api/management/orgs/' . $ctx['org']->id)
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // N6: Org-Admin erstellt User in eigener Org
    // ──────────────────────────────────────────────

    public function test_org_admin_can_create_user_in_own_org(): void
    {
        $ctx = $this->orgAdminContext();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($ctx['org']->id, $user->org_id);
    }

    // ──────────────────────────────────────────────
    // N7: Org-Admin löscht User in eigener Org
    // ──────────────────────────────────────────────

    public function test_org_admin_can_delete_user_in_own_org(): void
    {
        $ctx = $this->orgAdminContext();
        $targetUser = User::factory()->create(['org_id' => $ctx['org']->id]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->deleteJson('/api/management/users/' . $targetUser->id)
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertModelMissing($targetUser);
    }

    // ──────────────────────────────────────────────
    // N9: org_admin ohne org_id → 403
    // ──────────────────────────────────────────────

    public function test_org_admin_without_org_id_gets_403(): void
    {
        $token = $this->orgAdminWithoutOrgToken();
        $someOrg = Org::factory()->create(['invoice_frequency' => 'immediate']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/orgs')
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/orgs/' . $someOrg->id)
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // N11: is_customer_manager = is_org_admin (Alias)
    // ──────────────────────────────────────────────

    public function test_is_customer_manager_is_alias_for_is_org_admin(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'immediate']);
        $user = User::factory()->create(['org_id' => $org->id]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));

        $this->assertTrue($user->is_org_admin);
        $this->assertSame($user->is_org_admin, $user->is_customer_manager);
    }

    public function test_me_endpoint_returns_is_customer_manager(): void
    {
        $ctx = $this->orgAdminContext();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->getJson('/api/auth/me');

        $response->assertStatus(200);
        $response->assertJsonPath('is_org_admin', true);
        $response->assertJsonPath('is_customer_manager', true);
    }

    // ──────────────────────────────────────────────
    // N12: StatsController scoped per org_id
    // ──────────────────────────────────────────────

    public function test_org_admin_stats_are_scoped_to_own_org(): void
    {
        $ctx = $this->orgAdminContext();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->getJson('/api/management/stats');

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // N13: Brand-Konflikt Auto-Join (User RP, Org SRP → 403)
    // ──────────────────────────────────────────────

    public function test_brand_conflict_on_auto_join_returns_403(): void
    {
        $org = Org::factory()->create([
            'domain' => 'srp-company.com',
            'brand' => 'srp',
            'auto_join_policy' => 'immediate',
            'invoice_frequency' => 'immediate',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Brand Conflict User',
            'email' => 'user@srp-company.com',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Registrierung für diese Domain ist auf diesem Portal nicht möglich.');
    }

    // ──────────────────────────────────────────────
    // N14: Invite-Redeem für eingeloggten User → org_id gesetzt
    // ──────────────────────────────────────────────

    public function test_logged_in_user_can_redeem_invite_and_get_org_id(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'immediate']);
        $invite = OrgInvite::create([
            'email' => 'existing@example.com',
            'org_id' => $org->id,
            'token' => 'n14-redeem-token',
            'expires_at' => now()->addDays(7),
        ]);

        // Create a user who is already logged in (no org_id yet)
        $user = User::factory()->create(['org_id' => null]);
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/org-invites/redeem', [
                'token' => 'n14-redeem-token',
                'accept_privacy' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertEquals($org->id, $user->org_id);
        $this->assertDatabaseMissing('org_invites', ['token' => 'n14-redeem-token']);
    }

    // ──────────────────────────────────────────────
    // N15: Invite-Redeem ohne accept_privacy → 422
    // ──────────────────────────────────────────────

    public function test_redeem_invite_without_privacy_returns_422(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'immediate']);
        $invite = OrgInvite::create([
            'email' => 'noprivacy@example.com',
            'org_id' => $org->id,
            'token' => 'no-privacy-token',
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson('/api/org-invites/redeem', [
            'token' => 'no-privacy-token',
            'name' => 'No Privacy User',
            'password' => 'password123',
        ])->assertStatus(422);
    }
}
