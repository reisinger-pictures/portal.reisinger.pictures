<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvite;
use App\Mail\TenantInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class TenantInviteControllerTest extends TestCase
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
        $tenant = Tenant::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [
                'email' => 'test@example.com',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tenant_invites', [
            'email' => 'test@example.com',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_invite_sends_email(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Test Mandant']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [
                'email' => 'guest@example.com',
            ])
            ->assertStatus(200);

        Mail::assertQueued(TenantInviteMail::class, function ($mail) {
            return $mail->hasTo('guest@example.com') && $mail->tenantName === 'Test Mandant';
        });
    }

    public function test_invite_has_expiration(): void
    {
        $tenant = Tenant::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [
                'email' => 'future@example.com',
            ])
            ->assertStatus(200);

        $invite = TenantInvite::where('email', 'future@example.com')->first();
        $this->assertNotNull($invite);
        $this->assertTrue($invite->expires_at->isFuture());
        $this->assertGreaterThan(now()->addDays(6), $invite->expires_at);
    }

    public function test_cannot_invite_to_non_existent_tenant(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/tenants/non-existent-id/invites', [
                'email' => 'test@example.com',
            ])
            ->assertStatus(404);
    }

    public function test_invite_validates_email_format(): void
    {
        $tenant = Tenant::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [
                'email' => 'not-an-email',
            ])
            ->assertStatus(422);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_create_invite(): void
    {
        $tenant = Tenant::factory()->create();
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/tenants/{$tenant->id}/invites", [
                'email' => 'test@example.com',
            ])
            ->assertStatus(403);
    }

    public function test_check_valid_invite_returns_tenant_info(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Info Tenant']);
        $invite = TenantInvite::create([
            'email' => 'check@example.com',
            'tenant_id' => $tenant->id,
            'token' => 'valid-token-123',
            'expires_at' => now()->addDays(7),
        ]);

        $this->getJson("/api/tenant-invites/{$invite->token}")
            ->assertStatus(200)
            ->assertJson([
                'tenant_name' => 'Info Tenant',
                'email' => 'check@example.com',
            ]);
    }

    public function test_expired_invite_returns_404_on_check(): void
    {
        $tenant = Tenant::factory()->create();
        TenantInvite::create([
            'email' => 'expired@example.com',
            'tenant_id' => $tenant->id,
            'token' => 'expired-token',
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/tenant-invites/expired-token')
            ->assertStatus(404);
    }

    public function test_redeem_invite_creates_user_with_client_role(): void
    {
        $clientRole = Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]);
        $tenant = Tenant::factory()->create();
        $invite = TenantInvite::create([
            'email' => 'redeem@example.com',
            'tenant_id' => $tenant->id,
            'token' => 'redeem-token-456',
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson('/api/tenant-invites/redeem', [
            'token' => 'redeem-token-456',
            'name' => 'Redeemed User',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertStatus(200);

        $user = User::where('email', 'redeem@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Redeemed User', $user->name);
        $this->assertEquals($tenant->id, $user->tenant_id);
        $this->assertTrue($user->roles->contains($clientRole));

        $this->assertDatabaseMissing('tenant_invites', ['token' => 'redeem-token-456']);
    }

    public function test_cannot_use_expired_invite(): void
    {
        $tenant = Tenant::factory()->create();
        TenantInvite::create([
            'email' => 'toolate@example.com',
            'tenant_id' => $tenant->id,
            'token' => 'expired-redeem',
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/tenant-invites/redeem', [
            'token' => 'expired-redeem',
            'name' => 'Too Late',
            'password' => 'password123',
            'accept_privacy' => true,
        ])->assertStatus(404);
    }
}
