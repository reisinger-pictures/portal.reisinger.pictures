<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Brand isolation and trust model for org-invite redemption.
 *
 * Trust model (accepted by design): holding a valid invite token is the
 * credential — a logged-in account may redeem it even if it is not the invited
 * e-mail (magic-link trust). Brand isolation still applies: a brand-bound actor
 * may only redeem an invite of their own brand and can never be flipped to
 * another brand / cross-brand through a foreign or brand-less org invite.
 */
class OrgInviteBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function orgWithInvite(?string $brand, string $token, string $email): Org
    {
        $org = Org::factory()->create(['brand' => $brand, 'invoice_frequency' => 'immediate']);
        OrgInvite::create([
            'email' => $email,
            'org_id' => $org->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        return $org;
    }

    private function login(UserRole $role, ?string $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    public function test_brand_bound_actor_cannot_redeem_foreign_brand_invite(): void
    {
        $org = $this->orgWithInvite('rp', 'foreign-brand-invite', 'invited@example.com');
        $actor = $this->login(UserRole::CLIENT, self::OTHER_BRAND);
        $token = auth('api')->login($actor);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/org-invites/redeem', [
                'token' => 'foreign-brand-invite',
                'accept_privacy' => true,
            ])
            ->assertStatus(403);

        $actor->refresh();
        $this->assertNull($actor->org_id);
        $this->assertSame(self::OTHER_BRAND, $actor->getRawOriginal('brand'));
        $this->assertDatabaseHas('org_invites', ['token' => 'foreign-brand-invite', 'org_id' => $org->id]);
    }

    public function test_brand_bound_actor_cannot_redeem_brandless_org_invite(): void
    {
        // A brand-less org invite must never flip a brand-bound actor to cross-brand.
        $org = Org::create(['name' => 'Brandless Org', 'invoice_frequency' => 'immediate']);
        OrgInvite::create([
            'email' => 'invited@example.com',
            'org_id' => $org->id,
            'token' => 'brandless-invite',
            'expires_at' => now()->addDays(7),
        ]);
        $actor = $this->login(UserRole::CLIENT, 'rp');
        $token = auth('api')->login($actor);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/org-invites/redeem', [
                'token' => 'brandless-invite',
                'accept_privacy' => true,
            ])
            ->assertStatus(403);

        $actor->refresh();
        $this->assertNull($actor->org_id);
        $this->assertSame('rp', $actor->getRawOriginal('brand'));
    }

    public function test_brand_bound_actor_can_redeem_same_brand_invite(): void
    {
        $org = $this->orgWithInvite('rp', 'same-brand-invite', 'invited@example.com');
        $actor = $this->login(UserRole::CLIENT, 'rp');
        $token = auth('api')->login($actor);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/org-invites/redeem', [
                'token' => 'same-brand-invite',
                'accept_privacy' => true,
            ])
            ->assertOk();

        $actor->refresh();
        $this->assertEquals($org->id, $actor->org_id);
        $this->assertSame('rp', $actor->getRawOriginal('brand'));
    }

    public function test_cross_brand_actor_can_redeem_invite(): void
    {
        $org = $this->orgWithInvite('rp', 'cross-brand-invite', 'invited@example.com');
        $actor = $this->login(UserRole::ADMIN, null);
        $token = auth('api')->login($actor);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/org-invites/redeem', [
                'token' => 'cross-brand-invite',
                'accept_privacy' => true,
            ])
            ->assertOk();

        $this->assertEquals($org->id, $actor->fresh()->org_id);
    }

    public function test_guest_can_still_redeem_invite(): void
    {
        $org = $this->orgWithInvite('rp', 'guest-invite', 'guest@example.com');

        $this->postJson('/api/org-invites/redeem', [
            'token' => 'guest-invite',
            'name' => 'Guest User',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertOk();

        $user = User::where('email', 'guest@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($org->id, $user->org_id);
        $this->assertSame('rp', $user->getRawOriginal('brand'));
    }

    public function test_logged_out_flow_cannot_move_existing_foreign_brand_account(): void
    {
        // An existing brand-bound account must never be rebranded by a foreign invite,
        // even when redeemed without a session.
        $org = $this->orgWithInvite('rp', 'existing-account-invite', 'existing@example.com');
        $existing = User::factory()->create(['email' => 'existing@example.com', 'brand' => self::OTHER_BRAND]);

        $this->postJson('/api/org-invites/redeem', [
            'token' => 'existing-account-invite',
            'name' => 'Existing User',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertStatus(403);

        $existing->refresh();
        $this->assertNull($existing->org_id);
        $this->assertSame(self::OTHER_BRAND, $existing->getRawOriginal('brand'));
        $this->assertDatabaseHas('org_invites', ['token' => 'existing-account-invite', 'org_id' => $org->id]);
    }

    public function test_brand_bound_admin_cannot_invite_to_foreign_brand_org(): void
    {
        $org = Org::factory()->create(['brand' => self::OTHER_BRAND]);
        $actor = $this->login(UserRole::ADMIN, 'rp');
        $token = auth('api')->login($actor);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", ['email' => 'someone@example.com'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('org_invites', ['email' => 'someone@example.com']);
    }
}
