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
