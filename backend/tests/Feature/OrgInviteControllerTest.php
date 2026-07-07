<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Mail\OrgInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class OrgInviteControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        return auth('api')->login($user);
    }

    private function clientToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        return auth('api')->login($user);
    }

    public function test_admin_can_create_invite(): void
    {
        $org = Org::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [
                'email' => 'test@example.com',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('org_invites', [
            'email' => 'test@example.com',
            'org_id' => $org->id,
        ]);
    }

    public function test_invite_sends_email(): void
    {
        $org = Org::factory()->create(['name' => 'Test Mandant']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [
                'email' => 'guest@example.com',
            ])
            ->assertStatus(200);

        Mail::assertQueued(OrgInviteMail::class, function ($mail) {
            return $mail->hasTo('guest@example.com') && $mail->orgName === 'Test Mandant';
        });
    }

    public function test_invite_has_expiration(): void
    {
        $org = Org::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [
                'email' => 'future@example.com',
            ])
            ->assertStatus(200);

        $invite = OrgInvite::where('email', 'future@example.com')->first();
        $this->assertNotNull($invite);
        $this->assertTrue($invite->expires_at->isFuture());
        $this->assertGreaterThan(now()->addDays(6), $invite->expires_at);
    }

    public function test_cannot_invite_to_non_existent_org(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs/non-existent-id/invites', [
                'email' => 'test@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_invite_validates_email_format(): void
    {
        $org = Org::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [
                'email' => 'not-an-email',
            ])
            ->assertStatus(422);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_create_invite(): void
    {
        $org = Org::factory()->create();
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/orgs/{$org->id}/invites", [
                'email' => 'test@example.com',
            ])
            ->assertStatus(403);
    }

    public function test_check_valid_invite_returns_org_info(): void
    {
        $org = Org::factory()->create(['name' => 'Info Org']);
        $invite = OrgInvite::create([
            'email' => 'check@example.com',
            'org_id' => $org->id,
            'token' => 'valid-token-123',
            'expires_at' => now()->addDays(7),
        ]);

        $this->getJson("/api/org-invites/{$invite->token}")
            ->assertStatus(200)
            ->assertJson([
                'org_name' => 'Info Org',
                'email' => 'check@example.com',
            ]);
    }

    public function test_expired_invite_returns_404_on_check(): void
    {
        $org = Org::factory()->create();
        OrgInvite::create([
            'email' => 'expired@example.com',
            'org_id' => $org->id,
            'token' => 'expired-token',
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/org-invites/expired-token')
            ->assertStatus(404);
    }

    public function test_redeem_invite_creates_user_with_client_role(): void
    {
        $clientRole = Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]);
        $org = Org::factory()->create();
        $invite = OrgInvite::create([
            'email' => 'redeem@example.com',
            'org_id' => $org->id,
            'token' => 'redeem-token-456',
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson('/api/org-invites/redeem', [
            'token' => 'redeem-token-456',
            'name' => 'Redeemed User',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertStatus(200);

        $user = User::where('email', 'redeem@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Redeemed User', $user->name);
        $this->assertEquals($org->id, $user->org_id);
        $this->assertTrue($user->roles->contains($clientRole));

        $this->assertDatabaseMissing('org_invites', ['token' => 'redeem-token-456']);
    }

    public function test_cannot_use_expired_invite(): void
    {
        $org = Org::factory()->create();
        OrgInvite::create([
            'email' => 'toolate@example.com',
            'org_id' => $org->id,
            'token' => 'expired-redeem',
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/org-invites/redeem', [
            'token' => 'expired-redeem',
            'name' => 'Too Late',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertStatus(404);
    }
}
