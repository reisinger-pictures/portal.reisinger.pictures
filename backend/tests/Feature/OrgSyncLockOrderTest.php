<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LockRenderingSQLiteGrammar;
use Tests\TestCase;

/**
 * D-40: the org membership sync locks the actor, the current members and the
 * incoming members in ONE ascending lock set.
 *
 * The old order locked the actor row on its own, before the sorted union of
 * the member rows (org → actor → members → sorted union). Two syncs over
 * different orgs whose actors are members of each other's org could therefore
 * hold each other's actor row and wait for the other member row — a lock
 * cycle the database breaks by aborting one transaction. No data is corrupted,
 * but the request fails.
 *
 * The fix keeps the org row as the first lock (a consistent table order,
 * orgs before users) and takes every user row this sync may touch in a single
 * primary-key range scan, so all transactions take the same rows in the same
 * ascending order and the actor/member cycle cannot form.
 *
 * The lock set is assembled from an unlocked read of the member set. That set
 * is stable against other *syncs* of the same org (they would have to take
 * the same org row), but not against a member that a non-sync writer adds
 * after that read — such a member is not in the lock set and is therefore not
 * detached by that sync. The trade-off and its bound are recorded in
 * `features/security/2026-10-18-review-closure.md`; none of
 * `OrgInviteController::redeem()`, `UserController::store()` or
 * `AuthController::register()`/`resetPassword()` takes the org row lock, so
 * they change a membership without it. `OrgController::destroy()` is not one
 * of them: since it locks the org row first (D-40 follow-up), it takes the
 * same org row lock as this sync and changes a membership under it.
 *
 * The deadlock itself needs two independent database connections; see
 * `tests/scripts/org-sync-deadlock-mariadb-probe.sh` (CI-only, it needs
 * Docker). This test pins the contract that makes the cycle impossible: one
 * ascending lock statement over the users table, with the actor inside it.
 *
 * Regression proof: with the old order the three lock tests fail — the
 * statement count is three instead of one
 * (`select * from "users" where "users"."id" = ? limit 1 for update` for the
 * actor, one for the members, one for the sorted union) and the actor id is
 * missing from the union's bindings. The seven behaviour tests pass with both
 * orders, which is what shows that the lock order is the only difference.
 */
class OrgSyncLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND = 'rp';

    private const OTHER_BRAND = 'srp';

    private const LOCK_MARKER = '/* for update */';

    protected function setUp(): void
    {
        parent::setUp();

        // The SQLite grammar compiles a row lock to an empty string. This
        // grammar renders it as a comment, so the executed statements show
        // which statement carried a lock.
        $connection = DB::connection();
        $connection->setQueryGrammar(new LockRenderingSQLiteGrammar($connection));
    }

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    private function org(string $brand = self::BRAND): Org
    {
        return Org::factory()->create([
            'brand' => $brand,
            'invoice_frequency' => 'immediate',
        ]);
    }

    /**
     * @param  array<int, UserRole>  $roles
     */
    private function user(?Org $org, array $roles = [], string $brand = self::BRAND): User
    {
        $user = User::factory()->create([
            'brand' => $brand,
            'org_id' => $org?->id,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach($this->role($role));
        }

        return $user;
    }

    /**
     * @param  array<int, array{sql: string, bindings: array<int, mixed>}>|null  $collected
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    private function collectStatements(callable $callback, ?array &$collected = null): array
    {
        $collected = [];

        DB::listen(function (QueryExecuted $query) use (&$collected): void {
            $collected[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $collected;
    }

    /**
     * The statements that carried a row lock.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    private function lockStatements(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            fn (array $statement): bool => str_contains($statement['sql'], self::LOCK_MARKER),
        ));
    }

    /**
     * @param  array<int, array{sql: string, bindings: array<int, mixed>}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>}>
     */
    private function userLockStatements(array $statements): array
    {
        return array_values(array_filter(
            $this->lockStatements($statements),
            fn (array $statement): bool => str_contains($statement['sql'], 'from "users"'),
        ));
    }

    // ── D-40: the lock set ───────────────────────────────────────────────────

    public function test_the_actor_and_the_members_are_locked_in_one_ascending_lock_set(): void
    {
        $org = $this->org();
        // The actor is an admin who is *not* a member of the org: the lock set
        // must contain the actor row even when no membership points at it.
        $admin = $this->user(null, [UserRole::ADMIN]);
        $member = $this->user($org, [UserRole::CLIENT]);
        $incoming = $this->user(null, [UserRole::CLIENT]);

        $token = auth('api')->login($admin);

        $statements = $this->collectStatements(function () use ($token, $org, $incoming): void {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$incoming->id]])
                ->assertOk();
        });

        $lockStatements = $this->lockStatements($statements);
        $userLockStatements = $this->userLockStatements($statements);

        // The org row is locked first: the table order is orgs before users, so
        // the org lock can never close a cycle with a user lock.
        $this->assertNotEmpty($lockStatements);
        $this->assertStringContainsString('from "orgs"', $lockStatements[0]['sql']);

        // Exactly one statement locks user rows.
        $this->assertCount(
            1,
            $userLockStatements,
            'The user rows must be locked in one query, got: '.implode(' | ', array_column($userLockStatements, 'sql')),
        );

        $userLock = $userLockStatements[0];

        // It is the sorted union of the actor, the members and the incoming
        // users, not a single row and not the members alone.
        $this->assertStringContainsString('where "id" in (', $userLock['sql']);
        $this->assertStringContainsString('order by "id" asc', $userLock['sql']);

        // The actor is part of that set.
        $this->assertContains($admin->id, $userLock['bindings']);
        $this->assertContains($member->id, $userLock['bindings']);
        $this->assertContains($incoming->id, $userLock['bindings']);

        // No statement locks a single user row by equality. That was the
        // separately locked actor of the old order — the row that broke the
        // ascending order and allowed the actor/member cycle.
        $singleRowLocks = array_values(array_filter(
            $userLockStatements,
            fn (array $statement): bool => (bool) preg_match('/where\s+(?:"users"\.)?"id" = \?/', $statement['sql']),
        ));
        $this->assertSame(
            [],
            $singleRowLocks,
            'The actor must not be locked on its own, got: '.implode(' | ', array_column($singleRowLocks, 'sql')),
        );

        // The set is taken in one ascending order.
        $sortedBindings = $userLock['bindings'];
        sort($sortedBindings);
        $this->assertSame($sortedBindings, $userLock['bindings']);

        // The membership was applied.
        $this->assertDatabaseHas('users', ['id' => $incoming->id, 'org_id' => $org->id]);
    }

    public function test_the_org_admin_actor_is_locked_inside_the_same_set(): void
    {
        $org = $this->org();
        $orgAdmin = $this->user($org, [UserRole::ORG_ADMIN]);
        $client = $this->user(null, [UserRole::CLIENT]);

        $token = auth('api')->login($orgAdmin);

        $statements = $this->collectStatements(function () use ($token, $org, $orgAdmin, $client): void {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->putJson("/api/management/orgs/{$org->id}/users", [
                    'user_ids' => [$orgAdmin->id, $client->id],
                ])
                ->assertOk();
        });

        $userLockStatements = $this->userLockStatements($statements);

        $this->assertCount(1, $userLockStatements);
        $this->assertContains($orgAdmin->id, $userLockStatements[0]['bindings']);
        $this->assertContains($client->id, $userLockStatements[0]['bindings']);
    }

    public function test_the_authorization_decision_is_taken_on_the_locked_actor_row(): void
    {
        $org = $this->org();
        $admin = $this->user(null, [UserRole::ADMIN]);
        $member = $this->user($org, [UserRole::CLIENT]);

        $token = auth('api')->login($admin);

        $authorization = app(AuthorizationService::class);
        $statements = [];
        $decisions = [];

        $this->partialMock(AuthorizationService::class)
            ->shouldReceive('isAdmin')
            ->atLeast()
            ->once()
            ->andReturnUsing(function (User $user) use ($authorization, &$decisions, &$statements): bool {
                $decisions[] = ['user' => $user, 'statements' => count($statements)];

                return $authorization->isAdmin($user);
            });

        $statements = $this->collectStatements(function () use ($token, $org): void {
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => []])
                ->assertOk();
        }, $statements);

        $userLockStatements = $this->userLockStatements($statements);

        // The actor row is locked before the decision is taken, so the roles
        // that authorize the sync come from the locked row.
        $this->assertCount(1, $userLockStatements);
        $this->assertContains($admin->id, $userLockStatements[0]['bindings']);
        $this->assertContains($member->id, $userLockStatements[0]['bindings']);

        $lockIndex = array_search($userLockStatements[0], $statements, true);
        $this->assertIsInt($lockIndex);

        // The decision inside the transaction was taken after the lock
        // statement, on the actor row that statement returned.
        $inTransactionDecisions = array_values(array_filter(
            $decisions,
            fn (array $decision): bool => $decision['statements'] > $lockIndex,
        ));

        $this->assertNotEmpty(
            $inTransactionDecisions,
            'The authorization decision must be taken after the lock statement.',
        );

        foreach ($inTransactionDecisions as $decision) {
            $this->assertSame($admin->id, $decision['user']->id);
            $this->assertTrue(
                $decision['user']->relationLoaded('roles'),
                'The decision must run on the locked actor row, with its roles.',
            );
        }
    }

    // ── the endpoint behaviour is unchanged ─────────────────────────────────

    public function test_an_admin_may_assign_a_user_to_the_org(): void
    {
        $org = $this->org();
        $admin = $this->user(null, [UserRole::ADMIN]);
        $client = $this->user(null, [UserRole::CLIENT]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$client->id]])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => $org->id]);
    }

    public function test_an_org_admin_may_attach_and_detach_a_client(): void
    {
        $org = $this->org();
        $orgAdmin = $this->user($org, [UserRole::ORG_ADMIN]);
        $client = $this->user(null, [UserRole::CLIENT]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($orgAdmin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$client->id]])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => $org->id]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($orgAdmin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => []])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => null]);
    }

    public function test_an_org_admin_cannot_remove_themselves(): void
    {
        $org = $this->org();
        $orgAdmin = $this->user($org, [UserRole::ORG_ADMIN]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($orgAdmin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => []])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $orgAdmin->id, 'org_id' => $org->id]);
    }

    public function test_a_forbidden_actor_is_rejected(): void
    {
        $org = $this->org();
        // A photographer passes the management middleware for this path but is
        // neither an admin nor an org admin of the org.
        $photographer = $this->user(null, [UserRole::PHOTOGRAPHER]);
        $client = $this->user(null, [UserRole::CLIENT]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($photographer)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$client->id]])
            ->assertForbidden()
            ->assertJsonPath('error', 'Forbidden');

        $this->assertDatabaseHas('users', ['id' => $client->id, 'org_id' => null]);
    }

    public function test_a_user_of_another_brand_is_rejected(): void
    {
        $org = $this->org();
        $admin = $this->user(null, [UserRole::ADMIN]);
        $foreignUser = $this->user(null, [UserRole::CLIENT], self::OTHER_BRAND);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$foreignUser->id]])
            ->assertStatus(422)
            ->assertJsonPath(
                'error',
                'Ein oder mehrere Benutzer sind für eine andere Marke registriert und können dieser Organisation nicht zugewiesen werden.',
            );

        $this->assertDatabaseHas('users', ['id' => $foreignUser->id, 'org_id' => null]);
    }

    public function test_a_super_admin_cannot_be_assigned_to_an_org(): void
    {
        $org = $this->org();
        $admin = $this->user(null, [UserRole::ADMIN]);
        $superAdmin = $this->user(null, [UserRole::SUPER_ADMIN]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$superAdmin->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Super-Admins können keiner Organisation zugewiesen werden.');

        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'org_id' => null]);
    }

    public function test_an_org_admin_cannot_import_a_higher_privileged_account(): void
    {
        $org = $this->org();
        $orgAdmin = $this->user($org, [UserRole::ORG_ADMIN]);
        $admin = $this->user(null, [UserRole::ADMIN]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($orgAdmin)])
            ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$admin->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Es dürfen nur Benutzer mit Rollen der eigenen Organisation hinzugefügt werden.');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'org_id' => null]);
    }
}
