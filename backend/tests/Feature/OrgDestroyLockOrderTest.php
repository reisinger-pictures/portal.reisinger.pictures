<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LockRenderingSQLiteGrammar;
use Tests\TestCase;

/**
 * D-40 (follow-up): `OrgController::destroy()` locks the org row before it
 * writes the member rows.
 *
 * `syncUsers()` locks the org row first and the user rows afterwards, so the
 * table order is orgs -> users. The destroy used to write the member rows
 * first and took the org row's lock only at the delete — the inverse order. A
 * sync of org X (holds the org row, waits for a member) and a destroy of org X
 * (holds that member, waits for the org row) could therefore still form a
 * lock cycle.
 *
 * The destroy now takes the same org row lock, in the same order, inside its
 * transaction: the org row is locked before any member row is written, so the
 * two paths cannot hold the two rows in opposite orders.
 *
 * The deadlock itself needs two independent database connections. The
 * two-connection measurement of this order is still open — it needs a host
 * with Docker. This test pins the order deterministically instead: the SQLite
 * grammar renders a row lock as a comment, so the executed statements show
 * which statement carried a lock and in which order the locks were taken.
 *
 * Regression proof: with the old order the lock-order test fails — the org
 * row carries no lock at all before the member update, so there is no org lock
 * that could precede the member write.
 *
 * ── the member order ────────────────────────────────────────────────────────
 *
 * The org lock alone is not enough. A destroy of org A and a `syncUsers()` of
 * org B whose set contains two members of A can only be free of a lock cycle
 * on the *user* rows if both paths take those rows in the same sequence. The
 * sync locks its whole set in ONE ascending statement (`where id in (...)
 * order by id asc`); the destroy used to lock its members one after another
 * in whatever order the plan returned them — there was no `ORDER BY` at all.
 * The destroy therefore now plans and loads the member rows in ascending id
 * order, the same order the sync uses.
 *
 * What these tests prove: the *order*. The org row is locked before any
 * member row, the member rows are locked in ascending id order, and the
 * destroy and the sync use the same ascending order on the same rows.
 *
 * What these tests do NOT prove: that a cycle is impossible in general. That
 * needs the two-connection measurement, which stays open. They also do not
 * make the two paths identical in shape: the destroy still locks its members
 * ONE STATEMENT AT A TIME (one write per member) while the sync locks them
 * in ONE range scan. The order agrees; the statement count does not. And
 * because the destroy writes the pivot row of a member before that member's
 * user row, the destroy touches `user_roles` before `users` while the sync
 * takes all of its `users` locks before any pivot write.
 *
 * Regression proof (member order): without the `orderBy` on the member read
 * the member plan and the member load carry no `order by ... asc`, so the
 * order of the member locks is undefined — the plan may return the members
 * in any order and the locks are taken in that order.
 *
 * The assertions on the order are strict about the *presence* and the
 * *direction* of the ordering and lax about its spelling: a single-table
 * query may order by `"id"` or by `"users"."id"`. The statements themselves
 * are located by what they read — the membership read, and the member read
 * by id — and not by their exact text, so a contract-equivalent query shape
 * is accepted.
 */
class OrgDestroyLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND = 'rp';

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

    private function org(): Org
    {
        return Org::factory()->create([
            'brand' => self::BRAND,
            'invoice_frequency' => 'immediate',
        ]);
    }

    /**
     * @param  array<int, UserRole>  $roles
     */
    private function user(?Org $org, array $roles = []): User
    {
        $user = User::factory()->create([
            'brand' => self::BRAND,
            'org_id' => $org?->id,
        ]);

        foreach ($roles as $role) {
            $user->roles()->attach($this->role($role));
        }

        return $user;
    }

    /**
     * Collect the executed statements with their bindings and the transaction
     * level they ran at.
     *
     * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
     */
    private function collectStatements(callable $callback): array
    {
        $collected = [];

        DB::listen(function (QueryExecuted $query) use (&$collected): void {
            $collected[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'level' => $query->connection->transactionLevel(),
            ];
        });

        $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $collected;
    }

    /**
     * The statements that carried a row lock.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
     */
    private function lockStatements(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            fn (array $statement): bool => str_contains($statement['sql'], self::LOCK_MARKER),
        ));
    }

    /**
     * The statements that locked a row of the orgs table.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
     */
    private function orgLockStatements(array $statements): array
    {
        return array_values(array_filter(
            $this->lockStatements($statements),
            fn (array $statement): bool => str_contains($statement['sql'], 'from "orgs"'),
        ));
    }

    /**
     * The index of the first statement that matches the predicate, if any.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     */
    private function firstIndex(array $statements, callable $predicate): ?int
    {
        foreach ($statements as $index => $statement) {
            if ($predicate($statement)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The indexes of the reads of the `users` table.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array<int, int>
     */
    private function userReadIndexes(array $statements): array
    {
        $indexes = [];

        foreach ($statements as $index => $statement) {
            if (preg_match('/\bfrom\s+"users"/', $statement['sql'])) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * The index of the statement that reads the membership of the org.
     *
     * The membership read is the one that filters the users by their org.
     * Its exact text is not part of the contract — only that it is the read
     * of the membership.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     */
    private function memberPlanIndex(array $statements): ?int
    {
        foreach ($this->userReadIndexes($statements) as $index) {
            if (preg_match('/"org_id"/', $statements[$index]['sql'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The index of the statement that reads the member rows by their id.
     *
     * The member rows are read by their id, in whatever shape that read
     * takes — a list of ids, a range scan, a qualified column. The statement
     * is therefore found by what it reads and not by its exact text.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     */
    private function memberLoadIndex(array $statements): ?int
    {
        $planIndex = $this->memberPlanIndex($statements);

        foreach ($this->userReadIndexes($statements) as $index) {
            if ($index === $planIndex) {
                continue;
            }

            if (preg_match('/(?:"users"\.)?"id"\s*(?:in|between|=|<|>)/i', $statements[$index]['sql'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The statement that reads the membership of the org.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array{sql: string, bindings: array<int, mixed>, level: int}|null
     */
    private function memberPlanStatement(array $statements): ?array
    {
        $index = $this->memberPlanIndex($statements);

        return $index === null ? null : $statements[$index];
    }

    /**
     * The statement that reads the member rows by their id.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array{sql: string, bindings: array<int, mixed>, level: int}|null
     */
    private function memberLoadStatement(array $statements): ?array
    {
        $index = $this->memberLoadIndex($statements);

        return $index === null ? null : $statements[$index];
    }

    /**
     * The statement, or a readable failure.
     *
     * A statement that cannot be found is a contract violation, not an
     * accident of the SQL text: the failure names what was looked for and
     * lists what was executed.
     *
     * @param  array{sql: string, bindings: array<int, mixed>, level: int}|null  $statement
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array{sql: string, bindings: array<int, mixed>, level: int}
     */
    private function requireStatement(?array $statement, string $what, array $statements): array
    {
        if ($statement === null) {
            $this->fail(sprintf(
                "The %s statement was not found in the executed statements.\nExecuted statements:\n  %s",
                $what,
                implode("\n  ", array_column($statements, 'sql')),
            ));
        }

        return $statement;
    }

    /**
     * Assert that the statement orders the user rows by ascending id.
     *
     * The qualification of the column is not part of the contract: a
     * single-table query may order by `"id"` or by `"users"."id"`. The
     * presence of the ordering and its direction are: without them the order
     * of the member locks is undefined.
     */
    private function assertAscendingIdOrder(string $sql, string $what): void
    {
        $this->assertMatchesRegularExpression(
            '/order\s+by\s+(?:"users"\.)?"id"\s+asc\b/i',
            $sql,
            sprintf('%s must order the user rows by ascending id, got: %s', $what, $sql),
        );
    }

    /**
     * The statement that locks the user rows of the sync.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array{sql: string, bindings: array<int, mixed>, level: int}|null
     */
    private function userLockStatement(array $statements): ?array
    {
        foreach ($statements as $statement) {
            if (str_contains($statement['sql'], self::LOCK_MARKER)
                && str_contains($statement['sql'], 'from "users"')) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * The statements that write a member row.
     *
     * The destroy does not lock the member rows with a `for update` read — it
     * writes them one after another, and the write is what takes the
     * exclusive row lock. The order of the writes is therefore the order of
     * the member locks.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array<int, array{sql: string, bindings: array<int, mixed>, level: int}>
     */
    private function memberLockStatements(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            fn (array $statement): bool => str_starts_with($statement['sql'], 'update "users" set'),
        ));
    }

    /**
     * The member ids whose row was locked, in the order the locks were taken.
     *
     * @param  array<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
     * @return array<int, string>
     */
    private function lockedMemberIds(array $statements): array
    {
        return array_map(
            fn (array $statement): string => (string) $statement['bindings'][count($statement['bindings']) - 1],
            $this->memberLockStatements($statements),
        );
    }

    // ── D-40 (follow-up): the lock order of the destroy ──────────────────────

    public function test_the_org_row_is_locked_before_the_member_rows_are_updated(): void
    {
        $org = $this->org();
        $org->default_role_id = $this->role(UserRole::CLIENT)->id;
        $org->default_flatrate_level = 'web';
        $org->can_purchase_upgrades = true;
        $org->save();

        $member = $this->user($org, [UserRole::CLIENT]);
        $member->flatrate_level = 'web';
        $member->can_purchase_upgrades = true;
        $member->save();

        $admin = $this->user(null, [UserRole::ADMIN]);

        $statements = $this->collectStatements(function () use ($admin, $org): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->deleteJson("/api/management/orgs/{$org->id}")
                ->assertOk()
                ->assertJsonPath('success', true);
        });

        // The org row is locked exactly once.
        $orgLocks = $this->orgLockStatements($statements);
        $this->assertCount(
            1,
            $orgLocks,
            'The org row must be locked exactly once, got: '.implode(' | ', array_column($orgLocks, 'sql')),
        );

        $orgLock = $orgLocks[0];
        $orgLockIndex = array_search($orgLock, $statements, true);
        $this->assertIsInt($orgLockIndex);

        // The lock is on the org row itself, not on a member row.
        $this->assertStringContainsString('from "orgs"', $orgLock['sql']);
        $this->assertContains($org->id, $orgLock['bindings']);

        // The org lock is the first lock of the request: the table order is
        // orgs -> users.
        $lockStatements = $this->lockStatements($statements);
        $this->assertNotEmpty($lockStatements);
        $this->assertSame(
            $orgLock,
            $lockStatements[0],
            'The org row must be the first locked row, got: '.implode(' | ', array_column($lockStatements, 'sql')),
        );

        // The member rows are written after the org lock. A member write is
        // the role detach or the update of the member row itself.
        $memberWriteIndex = $this->firstIndex(
            $statements,
            fn (array $statement): bool => str_starts_with($statement['sql'], 'update "users"')
                || str_starts_with($statement['sql'], 'delete from "user_roles"'),
        );

        $this->assertNotNull(
            $memberWriteIndex,
            'The member rows must be written, got: '.implode(' | ', array_column($statements, 'sql')),
        );

        $this->assertLessThan(
            $memberWriteIndex,
            $orgLockIndex,
            'The org row must be locked before the member rows are written, got: '.implode(' | ', array_column($statements, 'sql')),
        );
    }

    public function test_the_org_lock_is_taken_inside_the_transaction(): void
    {
        $org = $this->org();
        $member = $this->user($org, [UserRole::CLIENT]);
        $admin = $this->user(null, [UserRole::ADMIN]);

        $statements = $this->collectStatements(function () use ($admin, $org): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->deleteJson("/api/management/orgs/{$org->id}")
                ->assertOk();
        });

        $orgLocks = $this->orgLockStatements($statements);
        $this->assertCount(1, $orgLocks);

        $orgLock = $orgLocks[0];

        // The lock runs deeper in the transaction stack than the request's
        // first query (the actor lookup, outside the transaction): the lock
        // is taken inside the destroy's own transaction, not before it.
        $this->assertGreaterThan(
            $statements[0]['level'],
            $orgLock['level'],
            'The org row must be locked inside the transaction.',
        );
    }

    public function test_the_destroy_locks_the_org_row_the_same_way_the_sync_does(): void
    {
        $syncOrg = $this->org();
        $destroyOrg = $this->org();
        $admin = $this->user(null, [UserRole::ADMIN]);

        $syncStatements = $this->collectStatements(function () use ($admin, $syncOrg): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->putJson("/api/management/orgs/{$syncOrg->id}/users", ['user_ids' => []])
                ->assertOk();
        });

        $destroyStatements = $this->collectStatements(function () use ($admin, $destroyOrg): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->deleteJson("/api/management/orgs/{$destroyOrg->id}")
                ->assertOk();
        });

        $syncOrgLocks = $this->orgLockStatements($syncStatements);
        $destroyOrgLocks = $this->orgLockStatements($destroyStatements);

        $this->assertCount(1, $syncOrgLocks);
        $this->assertCount(1, $destroyOrgLocks);

        // The same statement, on the same row: the same table, the same
        // primary key predicate, the same lock.
        $this->assertSame(
            $syncOrgLocks[0]['sql'],
            $destroyOrgLocks[0]['sql'],
            'The destroy must take the same org row lock as the sync.',
        );

        $this->assertContains($syncOrg->id, $syncOrgLocks[0]['bindings']);
        $this->assertContains($destroyOrg->id, $destroyOrgLocks[0]['bindings']);
    }

    // ── D-40 (follow-up): the member order of the destroy ──────────────────

    public function test_the_member_rows_are_locked_in_ascending_id_order(): void
    {
        $org = $this->org();
        $org->default_role_id = $this->role(UserRole::CLIENT)->id;
        $org->save();

        $members = [];
        foreach (range(1, 3) as $ignored) {
            $members[] = $this->user($org, [UserRole::CLIENT]);
        }

        $admin = $this->user(null, [UserRole::ADMIN]);

        $statements = $this->collectStatements(function () use ($admin, $org): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->deleteJson("/api/management/orgs/{$org->id}")
                ->assertOk()
                ->assertJsonPath('success', true);
        });

        // The member set is planned in ascending id order. Without the
        // `order by` the plan may return the members in any order — and that
        // order was the order the member rows were locked in.
        $plan = $this->requireStatement(
            $this->memberPlanStatement($statements),
            'member plan',
            $statements,
        );
        $this->assertAscendingIdOrder($plan['sql'], 'The member plan');

        // The member rows are loaded in ascending id order. The load is what
        // the loop below iterates over, so its order is the order of the
        // member locks.
        $load = $this->requireStatement(
            $this->memberLoadStatement($statements),
            'member load',
            $statements,
        );
        $this->assertAscendingIdOrder($load['sql'], 'The member load');

        // The member set, in ascending id order. It is derived from the
        // members the destroy had to detach — not from the bindings of the
        // load — so the assertions below hold for every shape the load may
        // take.
        $ascending = array_map(fn (User $member): string => $member->id, $members);
        sort($ascending, SORT_STRING);

        // The member locks: the writes that take the exclusive row lock.
        $memberLocks = $this->memberLockStatements($statements);
        $this->assertCount(
            count($members),
            $memberLocks,
            'Every member row must be locked, got: '.implode(' | ', array_column($statements, 'sql')),
        );

        $lockedIds = $this->lockedMemberIds($statements);

        $this->assertEqualsCanonicalizing(
            $ascending,
            $lockedIds,
            'Every member row must be locked.',
        );

        $this->assertSame(
            $ascending,
            $lockedIds,
            'The member rows must be locked in ascending id order.',
        );

        // The org lock precedes every member lock: the table order is
        // orgs -> users.
        $orgLocks = $this->orgLockStatements($statements);
        $this->assertCount(
            1,
            $orgLocks,
            'The org row must be locked exactly once, got: '.implode(' | ', array_column($orgLocks, 'sql')),
        );

        $orgLockIndex = array_search($orgLocks[0], $statements, true);
        $firstMemberLockIndex = array_search($memberLocks[0], $statements, true);
        $this->assertIsInt($orgLockIndex);
        $this->assertIsInt($firstMemberLockIndex);
        $this->assertLessThan(
            $firstMemberLockIndex,
            $orgLockIndex,
            'The org row must be locked before the member rows are locked, got: '.implode(' | ', array_column($statements, 'sql')),
        );
    }

    public function test_the_destroy_and_the_sync_take_the_member_rows_in_the_same_ascending_order(): void
    {
        $org = $this->org();
        $first = $this->user($org);
        $second = $this->user($org);
        $admin = $this->user(null, [UserRole::ADMIN]);

        // The sync locks the member rows in ONE ascending statement.
        $syncStatements = $this->collectStatements(function () use ($admin, $org, $first, $second): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->putJson("/api/management/orgs/{$org->id}/users", ['user_ids' => [$first->id, $second->id]])
                ->assertOk();
        });

        // The destroy locks the same member rows one after another.
        $destroyStatements = $this->collectStatements(function () use ($admin, $org): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
                ->deleteJson("/api/management/orgs/{$org->id}")
                ->assertOk();
        });

        $syncLock = $this->requireStatement(
            $this->userLockStatement($syncStatements),
            'sync user lock',
            $syncStatements,
        );
        $this->assertAscendingIdOrder($syncLock['sql'], 'The sync lock');

        $destroyLoad = $this->requireStatement(
            $this->memberLoadStatement($destroyStatements),
            'member load',
            $destroyStatements,
        );
        $this->assertAscendingIdOrder($destroyLoad['sql'], 'The destroy member load');

        // Both paths take the same rows in the same sequence: the members
        // appear in ascending id order in the lock set of the sync and in
        // the lock sequence of the destroy.
        $ascending = [$first->id, $second->id];
        sort($ascending, SORT_STRING);

        $syncMemberIds = array_values(array_intersect(
            array_map('strval', $syncLock['bindings']),
            $ascending,
        ));

        $this->assertSame(
            $ascending,
            $syncMemberIds,
            'The sync must lock the member rows in ascending id order.',
        );
        $this->assertSame(
            $ascending,
            $this->lockedMemberIds($destroyStatements),
            'The destroy must lock the member rows in ascending id order.',
        );
    }

    // ── the destroy behaviour is unchanged ──────────────────────────────────

    public function test_the_destroy_still_detaches_the_members_and_deletes_the_org(): void
    {
        $org = $this->org();
        $org->default_role_id = $this->role(UserRole::CLIENT)->id;
        $org->default_flatrate_level = 'web';
        $org->can_purchase_upgrades = true;
        $org->save();

        $member = $this->user($org, [UserRole::CLIENT]);
        $member->flatrate_level = 'web';
        $member->can_purchase_upgrades = true;
        $member->save();

        $admin = $this->user(null, [UserRole::ADMIN]);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->deleteJson("/api/management/orgs/{$org->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertModelMissing($org);

        $member = $member->fresh();
        $this->assertNotNull($member);
        $this->assertNull($member->org_id);
        $this->assertSame('none', $member->flatrate_level);
        // `can_purchase_upgrades` carries no boolean cast on the model: the
        // column is stored as an integer flag.
        $this->assertSame(0, (int) $member->can_purchase_upgrades);
        $this->assertFalse($member->roles->contains('name', UserRole::CLIENT->value));
    }
}
