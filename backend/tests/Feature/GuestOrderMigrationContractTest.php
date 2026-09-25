<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the portable V037 order-owner migration contract.
 */
class GuestOrderMigrationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_mariadb_branch_uses_signal_triggers_instead_of_a_check_on_the_foreign_key_column(): void
    {
        $source = $this->migrationSource();
        $mariadbBranch = $this->mariadbBranch($source);

        $this->assertStringNotContainsString(
            'ALTER TABLE orders ADD CONSTRAINT orders_owner_not_both CHECK',
            $mariadbBranch,
        );
        $this->assertStringContainsString('CREATE TRIGGER orders_owner_not_both_insert', $mariadbBranch);
        $this->assertStringContainsString('CREATE TRIGGER orders_owner_not_both_update', $mariadbBranch);
        $this->assertSame(1, substr_count(
            $mariadbBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_insert',
        ));
        $this->assertSame(1, substr_count(
            $mariadbBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_update',
        ));
        $this->assertOccursBefore(
            $mariadbBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_insert',
            'CREATE TRIGGER orders_owner_not_both_insert',
        );
        $this->assertOccursBefore(
            $mariadbBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_update',
            'CREATE TRIGGER orders_owner_not_both_update',
        );
        $this->assertSame(2, substr_count($mariadbBranch, "SIGNAL SQLSTATE '45000'"));
        $this->assertSame(2, substr_count(
            $mariadbBranch,
            'NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL',
        ));
        $this->assertSame(2, substr_count(
            $mariadbBranch,
            "MESSAGE_TEXT = 'orders_owner_not_both'",
        ));

        // The portable branches remain intentionally different: SQLite uses
        // its own trigger syntax, while PostgreSQL keeps the CHECK constraint.
        $this->assertStringContainsString("elseif (\$driver === 'sqlite')", $source);
        $this->assertStringContainsString("elseif (\$driver === 'pgsql')", $source);
        $this->assertStringContainsString(
            'ALTER TABLE orders ADD CONSTRAINT orders_owner_not_both CHECK',
            $source,
        );

        $sqliteBranch = $this->sqliteBranch($source);
        $this->assertSame(1, substr_count(
            $sqliteBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_insert',
        ));
        $this->assertSame(1, substr_count(
            $sqliteBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_update',
        ));
        $this->assertOccursBefore(
            $sqliteBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_insert',
            'CREATE TRIGGER orders_owner_not_both_insert',
        );
        $this->assertOccursBefore(
            $sqliteBranch,
            'DROP TRIGGER IF EXISTS orders_owner_not_both_update',
            'CREATE TRIGGER orders_owner_not_both_update',
        );

        $this->assertStringContainsString('if (! $this->hasPostgresOwnerConstraint())', $source);
        $this->assertStringContainsString('FROM pg_constraint', $source);
        $this->assertStringContainsString("conrelid = 'orders'::regclass", $source);
        $this->assertStringContainsString("contype = 'c'", $source);
        $this->assertStringContainsString('conname = ?', $source);
    }

    public function test_v037_retry_replays_existing_postgres_check_constraint(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This assertion exercises the PostgreSQL-specific constraint probe.');
        }

        $this->assertSame(1, $this->postgresOwnerConstraintCount());

        $migration = require database_path('migrations/V037__add_guest_order_ownership.php');
        $migration->up();

        $this->assertSame(1, $this->postgresOwnerConstraintCount());
    }

    public function test_v037_retry_replays_after_partial_schema_ddl_and_trigger_failure(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            $this->markTestSkipped('The V037 retry regression targets the DDL branches with trigger creation.');
        }

        // Model a MariaDB auto-commit boundary: all schema DDL was committed,
        // then trigger creation failed before the migration could finish. A
        // retry must not repeat the committed column or any of its indexes.
        $this->assertTrue(Schema::hasColumn('orders', 'guest_id'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_id_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_owner_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_fingerprint_lookup_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_checkout_key_unique', 'unique'));

        // Leave the insert trigger in place to model a failure between the two
        // trigger statements. The SQLite branch must clean it up as well.
        DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_update');

        $migration = require database_path('migrations/V037__add_guest_order_ownership.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('orders', 'guest_id'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_id_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_owner_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_fingerprint_lookup_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_checkout_key_unique', 'unique'));

        $user = User::factory()->create();
        $this->assertDirectInsertRejectsTwoOwners(
            $user,
            'The replayed owner insert trigger did not reject two owners.',
        );

        // Also exercise the opposite partial state: a retry must add an index
        // and the unique key that were not present when the earlier attempt
        // stopped, while still tolerating the column and the other indexes.
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_guest_fingerprint_lookup_idx');
            $table->dropUnique('orders_guest_checkout_key_unique');
        });
        DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_insert');
        DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_update');

        $migration->up();

        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_fingerprint_lookup_idx'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_guest_checkout_key_unique', 'unique'));

        $this->assertDirectInsertRejectsTwoOwners(
            $user,
            'The recreated owner insert trigger did not reject two owners.',
        );
        $this->assertDirectUpdateRejectsTwoOwners(
            $user,
            'The recreated owner update trigger did not reject two owners.',
        );
    }

    public function test_direct_sql_insert_is_rejected_when_both_order_owners_are_present(): void
    {
        $user = User::factory()->create();

        $this->assertDirectInsertRejectsTwoOwners(
            $user,
            'The direct insert guard did not reject two owners.',
        );
    }

    public function test_direct_sql_update_is_rejected_when_both_order_owners_are_present(): void
    {
        $user = User::factory()->create();

        $this->assertDirectUpdateRejectsTwoOwners(
            $user,
            'The direct update guard did not reject two owners.',
        );
    }

    private function assertDirectInsertRejectsTwoOwners(User $user, string $failureMessage): void
    {
        try {
            DB::table('orders')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'guest_id' => (string) Str::uuid(),
                'status' => 'pending',
                'is_quote_request' => false,
                'total_amount' => 100,
                'created_at' => now(),
                'brand' => 'rp',
            ]);
            $this->fail($failureMessage);
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_owner_not_both', $exception->getMessage());
        }
    }

    private function assertDirectUpdateRejectsTwoOwners(User $user, string $failureMessage): void
    {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'guest_id' => null,
        ]);

        try {
            DB::table('orders')->where('id', $order->id)->update([
                'guest_id' => (string) Str::uuid(),
            ]);
            $this->fail($failureMessage);
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_owner_not_both', $exception->getMessage());
        }
    }

    private function assertOccursBefore(string $source, string $first, string $second): void
    {
        $firstPosition = strpos($source, $first);
        $secondPosition = strpos($source, $second, $firstPosition === false ? 0 : $firstPosition);

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertTrue($firstPosition < $secondPosition);
    }

    private function migrationSource(): string
    {
        $path = database_path('migrations/V037__add_guest_order_ownership.php');
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        return $source;
    }

    private function postgresOwnerConstraintCount(): int
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM pg_constraint WHERE conrelid = 'orders'::regclass AND conname = ? AND contype = 'c'",
            ['orders_owner_not_both'],
            false,
        );
    }

    private function sqliteBranch(string $source): string
    {
        $startMarker = "        } elseif (\$driver === 'sqlite') {";
        $endMarker = "        } elseif (\$driver === 'pgsql') {";
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker, $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    private function mariadbBranch(string $source): string
    {
        $startMarker = "if (in_array(\$driver, ['mysql', 'mariadb'], true)) {";
        $endMarker = "        } elseif (\$driver === 'sqlite') {";
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker, $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }
}
