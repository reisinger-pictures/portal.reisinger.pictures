<?php

namespace Tests\Feature;

use App\Enums\AutoJoinPolicy;
use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\OrgDefaultRolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrgDefaultRoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const ALLOWED_ROLE_NAMES = ['client', 'power_user', 'org_admin'];

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name]);
    }

    private function admin(string $roleName = 'admin', ?Org $org = null): User
    {
        $user = User::factory()->create([
            'brand' => 'rp',
            'org_id' => $org?->id,
        ]);
        $user->roles()->attach($this->role($roleName));

        return $user;
    }

    private function org(?Role $defaultRole = null, ?string $domain = null): Org
    {
        return Org::factory()->create([
            'brand' => 'rp',
            'domain' => $domain,
            'default_role_id' => $defaultRole?->id,
            'auto_join_policy' => AutoJoinPolicy::IMMEDIATE,
        ]);
    }

    private function resetToken(User $user): string
    {
        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        return $token;
    }

    private function resetPassword(User $user, string $token): void
    {
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
        ])->assertOk();
    }

    /** @return array<string, string> */
    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    public function test_each_allowlisted_role_is_inherited_by_persisted_role_id_in_both_creation_paths(): void
    {
        Mail::fake();

        foreach (self::ALLOWED_ROLE_NAMES as $roleName) {
            $domainSuffix = str_replace('_', '-', $roleName);
            $defaultRole = $this->role($roleName);
            $autoJoinOrg = $this->org($defaultRole, 'auto-'.$domainSuffix.'.example.com');
            $autoJoinUser = User::factory()->create([
                'brand' => 'rp',
                'email' => 'auto-'.$roleName.'@auto-'.$domainSuffix.'.example.com',
                'org_id' => null,
            ]);

            $this->resetPassword($autoJoinUser, $this->resetToken($autoJoinUser));

            $autoJoined = $autoJoinUser->fresh();
            $this->assertSame($autoJoinOrg->id, $autoJoined->org_id);
            $this->assertSame([$roleName], $autoJoined->roles()->pluck('name')->all());

            $managedOrg = $this->org($defaultRole);
            $orgAdmin = $this->admin(UserRole::ORG_ADMIN->value, $managedOrg);
            $response = $this->withHeaders($this->headersFor($orgAdmin))
                ->postJson('/api/management/users', [
                    'name' => 'New organization member',
                    'email' => 'managed-'.$roleName.'@example.com',
                ]);

            $response->assertOk();
            $createdId = $response->json('user.id');
            $created = User::query()->findOrFail($createdId);
            $this->assertSame($managedOrg->id, $created->org_id);
            $this->assertSame([$roleName], $created->roles()->pluck('name')->all());
        }
    }

    public function test_null_default_role_means_client_for_auto_join_and_org_admin_creation(): void
    {
        Mail::fake();
        $client = $this->role(UserRole::CLIENT->value);
        $autoJoinOrg = $this->org(null, 'null-default.example.com');
        $autoJoinUser = User::factory()->create([
            'brand' => 'rp',
            'email' => 'auto@null-default.example.com',
            'org_id' => null,
        ]);

        $this->resetPassword($autoJoinUser, $this->resetToken($autoJoinUser));

        $autoJoined = $autoJoinUser->fresh();
        $this->assertSame($autoJoinOrg->id, $autoJoined->org_id);
        $this->assertSame([$client->name], $autoJoined->roles()->pluck('name')->all());

        $managedOrg = $this->org();
        $orgAdmin = $this->admin(UserRole::ORG_ADMIN->value, $managedOrg);
        $response = $this->withHeaders($this->headersFor($orgAdmin))
            ->postJson('/api/management/users', [
                'name' => 'Null default member',
                'email' => 'managed-null-default@example.com',
            ]);

        $response->assertOk();
        $created = User::query()->findOrFail($response->json('user.id'));
        $this->assertSame($managedOrg->id, $created->org_id);
        $this->assertSame([$client->name], $created->roles()->pluck('name')->all());
    }

    public function test_unsupported_legacy_defaults_fall_back_to_client_in_both_creation_paths_and_are_logged(): void
    {
        Mail::fake();
        Log::spy();
        $client = $this->role(UserRole::CLIENT->value);
        foreach ([
            UserRole::PHOTOGRAPHER->value,
            UserRole::ADMIN->value,
            UserRole::SUPER_ADMIN->value,
            'legacy_unknown_role',
        ] as $unsupportedName) {
            $domainSuffix = Str::slug($unsupportedName);
            $unsupportedRole = $this->role($unsupportedName);
            $domain = 'legacy-'.$domainSuffix.'.example.com';
            $autoJoinOrg = $this->org($unsupportedRole, $domain);
            $autoJoinUser = User::factory()->create([
                'brand' => 'rp',
                'email' => 'auto-'.$domainSuffix.'@'.$domain,
                'org_id' => null,
            ]);

            $this->resetPassword($autoJoinUser, $this->resetToken($autoJoinUser));

            $autoJoined = $autoJoinUser->fresh();
            $this->assertSame($autoJoinOrg->id, $autoJoined->org_id);
            $this->assertSame([$client->name], $autoJoined->roles()->pluck('name')->all());
            $this->assertFalse($autoJoined->roles->contains('name', $unsupportedName));

            $managedOrg = $this->org($unsupportedRole);
            $orgAdmin = $this->admin(UserRole::ORG_ADMIN->value, $managedOrg);
            $response = $this->withHeaders($this->headersFor($orgAdmin))
                ->postJson('/api/management/users', [
                    'name' => 'Legacy default member',
                    'email' => 'managed-'.$domainSuffix.'@example.com',
                ]);

            $response->assertOk();
            $created = User::query()->findOrFail($response->json('user.id'));
            $this->assertSame($managedOrg->id, $created->org_id);
            $this->assertSame([$client->name], $created->roles()->pluck('name')->all());
            $this->assertFalse($created->roles->contains('name', $unsupportedName));
        }

        Log::shouldHaveReceived('warning')
            ->times(8)
            ->with(
                'Unsupported organization default role; using CLIENT for a new assignment.',
                \Mockery::on(fn (array $context): bool => array_keys($context) === [
                    'org_id',
                    'default_role_id',
                    'default_role_name',
                ]),
            );
    }

    public function test_default_role_validation_accepts_allowed_ids_and_rejects_every_other_role_name(): void
    {
        $admin = $this->admin();
        $headers = $this->headersFor($admin);
        $client = $this->role(UserRole::CLIENT->value);
        $powerUser = $this->role(UserRole::POWER_USER->value);
        $orgAdminRole = $this->role(UserRole::ORG_ADMIN->value);
        $org = $this->org($client);

        $this->withHeaders($headers)
            ->postJson('/api/management/orgs', [
                'name' => 'Explicit default role organization',
                'invoice_frequency' => 'immediate',
                'default_role_id' => $client->id,
            ])
            ->assertOk()
            ->assertJsonPath('org.default_role_id', $client->id);

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $powerUser->id,
            ])
            ->assertOk();
        $this->assertSame($powerUser->id, $org->fresh()->default_role_id);

        foreach ([
            UserRole::PHOTOGRAPHER->value,
            UserRole::ADMIN->value,
            UserRole::SUPER_ADMIN->value,
            'unknown_legacy_role',
        ] as $unsupportedName) {
            $unsupportedRole = $this->role($unsupportedName);

            $this->withHeaders($headers)
                ->putJson('/api/management/orgs/'.$org->id, [
                    'name' => $org->name,
                    'invoice_frequency' => 'immediate',
                    'default_role_id' => $unsupportedRole->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('default_role_id');

            $this->withHeaders($headers)
                ->postJson('/api/management/orgs', [
                    'name' => 'Invalid default role organization',
                    'invoice_frequency' => 'immediate',
                    'default_role_id' => $unsupportedRole->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('default_role_id');
        }

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $orgAdminRole->id,
            ])
            ->assertOk();
    }

    public function test_org_admin_can_edit_other_settings_but_cannot_change_the_default_role(): void
    {
        $client = $this->role(UserRole::CLIENT->value);
        $powerUser = $this->role(UserRole::POWER_USER->value);
        $org = $this->org($client);
        $orgAdmin = $this->admin(UserRole::ORG_ADMIN->value, $org);
        $headers = $this->headersFor($orgAdmin);

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => 'Edited by org admin',
                'invoice_frequency' => 'monthly',
            ])
            ->assertOk();
        $this->assertSame('Edited by org admin', $org->fresh()->name);
        $this->assertSame($client->id, $org->fresh()->default_role_id);

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => 'Must not save',
                'invoice_frequency' => 'monthly',
                'default_role_id' => $powerUser->id,
            ])
            ->assertForbidden();

        $this->assertSame('Edited by org admin', $org->fresh()->name);
        $this->assertSame($client->id, $org->fresh()->default_role_id);

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => 'Must not clear the default',
                'invoice_frequency' => 'monthly',
                'default_role_id' => null,
            ])
            ->assertForbidden();

        $this->assertSame('Edited by org admin', $org->fresh()->name);
        $this->assertSame($client->id, $org->fresh()->default_role_id);
    }

    public function test_super_admin_can_change_the_default_role(): void
    {
        $superAdmin = $this->admin(UserRole::SUPER_ADMIN->value);
        $powerUser = $this->role(UserRole::POWER_USER->value);
        $org = $this->org();

        $this->withHeaders($this->headersFor($superAdmin))
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $powerUser->id,
            ])
            ->assertOk();

        $this->assertSame($powerUser->id, $org->fresh()->default_role_id);
    }

    public function test_changing_the_default_does_not_rewrite_existing_members(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT->value);
        $powerUser = $this->role(UserRole::POWER_USER->value);
        $orgAdminRole = $this->role(UserRole::ORG_ADMIN->value);
        $org = $this->org($client);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $org->id]);
        $member->roles()->attach([$client->id, $powerUser->id]);

        $this->withHeaders($this->headersFor($admin))
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $orgAdminRole->id,
            ])
            ->assertOk();

        $this->assertSame($orgAdminRole->id, $org->fresh()->default_role_id);
        $this->assertSame(['client', 'power_user'], $member->fresh()->roles()->orderBy('name')->pluck('name')->all());
        $this->assertSame($org->id, $member->fresh()->org_id);
    }

    public function test_org_detail_exposes_invalid_default_role_information_for_repair(): void
    {
        $admin = $this->admin();
        $photographer = $this->role(UserRole::PHOTOGRAPHER->value);
        $org = $this->org($photographer);

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/management/orgs/'.$org->id)
            ->assertOk()
            ->assertJsonPath('default_role_id', $photographer->id)
            ->assertJsonPath('default_role_info.id', $photographer->id)
            ->assertJsonPath('default_role_info.name', UserRole::PHOTOGRAPHER->value)
            ->assertJsonPath('default_role_info.allowed', false);
    }

    public function test_missing_client_role_remains_a_clear_invariant_failure(): void
    {
        $client = $this->role(UserRole::CLIENT->value);
        $org = $this->org();
        $client->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Critical: Default CLIENT role missing in database.');

        app(OrgDefaultRolePolicy::class)->roleForNewAssignment($org);
    }
}
