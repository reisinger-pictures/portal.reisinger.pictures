<?php

namespace Tests\Feature;

use App\Enums\AutoJoinPolicy;
use App\Enums\UserRole;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrgMembershipPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    private function org(Role $defaultRole, array $attributes = []): Org
    {
        return Org::factory()->create(array_merge([
            'brand' => 'rp',
            'default_role_id' => $defaultRole->id,
            'invoice_frequency' => 'immediate',
        ], $attributes));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['brand' => 'rp']);
        $admin->roles()->attach($this->role(UserRole::ADMIN));

        return $admin;
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function invite(Org $org, string $email, string $token): OrgInvite
    {
        return OrgInvite::create([
            'email' => $email,
            'org_id' => $org->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /** @return list<string> */
    private function roleNames(User $user): array
    {
        return $user->roles()->orderBy('name')->pluck('name')->all();
    }

    public function test_logged_in_invitee_can_switch_orgs_without_carrying_org_roles(): void
    {
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);
        $adminRole = $this->role(UserRole::ADMIN);
        $orgA = $this->org($client);
        $orgB = $this->org($powerUser);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $orgA->id]);
        $member->roles()->attach([$client->id, $powerUser->id, $orgAdmin->id, $photographer->id, $adminRole->id]);
        $invite = $this->invite($orgB, $member->email, 'logged-in-org-switch-token');

        $this->withHeaders($this->headersFor($member))
            ->postJson('/api/org-invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($orgB->id, $member->fresh()->org_id);
        $this->assertSame(['admin', 'photographer', 'power_user'], $this->roleNames($member->fresh()));
        $this->assertDatabaseMissing('org_invites', ['token' => $invite->token]);
    }

    /**
     * The browser contract: the auth cookie is the session, and the invite
     * token in the body must not shadow it. The JWT parser reads a request
     * field named `token` before it reads the cookie, so this request is
     * anonymous unless the controller falls back to the cookie — and the guest
     * branch would then demand `name` and `password`.
     */
    public function test_a_logged_in_browser_redeems_its_invite_with_the_token_in_the_body(): void
    {
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgA = $this->org($client);
        $orgB = $this->org($powerUser);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $orgA->id]);
        $member->roles()->attach($client->id);
        $invite = $this->invite($orgB, $member->email, 'browser-cookie-org-switch-token');

        $jwt = auth('api')->login($member);
        Auth::forgetGuards();
        $csrf = $this->csrfCredentials();

        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers'])
            ->withCredentials()
            ->postJson('/api/org-invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($orgB->id, $member->fresh()->org_id);
        $this->assertSame([$powerUser->name], $this->roleNames($member->fresh()));
    }

    public function test_existing_email_account_can_switch_orgs_by_redeeming_its_invite(): void
    {
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $orgA = $this->org($client);
        $orgB = $this->org($powerUser);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $orgA->id]);
        $member->roles()->attach([$client->id, $orgAdmin->id]);
        $passwordHash = $member->password;
        $invite = $this->invite($orgB, $member->email, 'existing-account-org-switch-token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/org-invites/redeem', [
                'token' => $invite->token,
                'name' => 'Existing member',
                'password' => 'replacement-password-123',
                'accept_privacy' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($orgB->id, $member->fresh()->org_id);
        $this->assertSame([$powerUser->name], $this->roleNames($member->fresh()));
        $this->assertSame($passwordHash, $member->fresh()->password);
        $this->assertDatabaseMissing('org_invites', ['token' => $invite->token]);
    }

    public function test_management_sync_rejects_moving_a_member_from_another_org_without_changes(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);
        $orgA = $this->org($client);
        $orgB = $this->org($powerUser);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $orgA->id]);
        $member->roles()->attach([$client->id, $orgAdmin->id, $photographer->id]);
        $pendingInvite = $this->invite($orgB, $member->email, 'sync-conflict-preserves-invite-token');

        $this->withHeaders($this->headersFor($admin))
            ->putJson('/api/management/orgs/'.$orgB->id.'/users', [
                'user_ids' => [$member->id],
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'error',
                'Benutzer gehört bereits einer anderen Organisation an. Bitte entferne zuerst die bestehende Mitgliedschaft.',
            );

        $this->assertSame($orgA->id, $member->fresh()->org_id);
        $this->assertSame(['client', 'org_admin', 'photographer'], $this->roleNames($member->fresh()));
        $this->assertSame(0, $orgB->users()->count());
        $this->assertDatabaseHas('org_invites', [
            'id' => $pendingInvite->id,
            'token' => $pendingInvite->token,
        ]);
    }

    public function test_explicit_removal_clears_org_roles_before_invited_switch_to_new_default(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);
        $adminRole = $this->role(UserRole::ADMIN);
        $orgA = $this->org($client, [
            'default_flatrate_level' => 'web',
            'can_purchase_upgrades' => true,
        ]);
        $orgB = $this->org($powerUser);
        $member = User::factory()->create([
            'brand' => 'rp',
            'org_id' => $orgA->id,
            'flatrate_level' => 'web',
            'can_purchase_upgrades' => true,
        ]);
        $member->roles()->attach([$client->id, $powerUser->id, $orgAdmin->id, $photographer->id, $adminRole->id]);

        $this->withHeaders($this->headersFor($admin))
            ->putJson('/api/management/orgs/'.$orgA->id.'/users', ['user_ids' => []])
            ->assertOk();

        $detached = $member->fresh();
        $this->assertNull($detached->org_id);
        $this->assertSame(['admin', 'photographer'], $this->roleNames($detached));
        $this->assertSame('none', $detached->flatrate_level);
        $this->assertFalse((bool) $detached->can_purchase_upgrades);

        $invite = $this->invite($orgB, $member->email, 'removed-member-org-switch-token');
        $this->withHeaders($this->headersFor($member))
            ->postJson('/api/org-invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])
            ->assertOk();

        $joined = $member->fresh();
        $this->assertSame($orgB->id, $joined->org_id);
        $this->assertSame(['admin', 'photographer', 'power_user'], $this->roleNames($joined));
    }

    public function test_same_org_invite_and_membership_sync_remain_idempotent(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $org = $this->org($powerUser);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $org->id]);
        $member->roles()->attach([$client->id, $orgAdmin->id]);
        $invite = $this->invite($org, $member->email, 'same-org-invite-token');

        $this->withHeaders($this->headersFor($member))
            ->postJson('/api/org-invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])
            ->assertOk();

        $this->assertSame($org->id, $member->fresh()->org_id);
        $this->assertSame(['client', 'org_admin'], $this->roleNames($member->fresh()));

        $this->withHeaders($this->headersFor($admin))
            ->putJson('/api/management/orgs/'.$org->id.'/users', [
                'user_ids' => [$member->id],
            ])
            ->assertOk();

        $this->assertSame($org->id, $member->fresh()->org_id);
        $this->assertSame(['client', 'org_admin'], $this->roleNames($member->fresh()));
    }

    public function test_deferred_domain_auto_join_does_not_move_an_existing_org_member(): void
    {
        $client = $this->role(UserRole::CLIENT);
        $powerUser = $this->role(UserRole::POWER_USER);
        $orgAdmin = $this->role(UserRole::ORG_ADMIN);
        $orgA = $this->org($client);
        $this->org($powerUser, [
            'domain' => 'destination.example.com',
            'auto_join_policy' => AutoJoinPolicy::IMMEDIATE,
        ]);
        $member = User::factory()->create([
            'brand' => 'rp',
            'email' => 'member@destination.example.com',
            'org_id' => $orgA->id,
        ]);
        $member->roles()->attach([$client->id, $orgAdmin->id]);
        $resetToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $member->email,
            'token' => Hash::make($resetToken),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $member->email,
            'token' => $resetToken,
            'password' => 'UpdatedSecurePassword123!',
        ])->assertOk();

        $this->assertSame($orgA->id, $member->fresh()->org_id);
        $this->assertSame(['client', 'org_admin'], $this->roleNames($member->fresh()));
        $this->assertFalse($member->fresh()->roles->contains('id', $powerUser->id));
    }
}
