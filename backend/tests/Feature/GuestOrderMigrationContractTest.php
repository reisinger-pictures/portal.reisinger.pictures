<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    }

    public function test_direct_sql_insert_is_rejected_when_both_order_owners_are_present(): void
    {
        $user = User::factory()->create();

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
            $this->fail('The direct insert guard did not reject two owners.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_owner_not_both', $exception->getMessage());
        }
    }

    public function test_direct_sql_update_is_rejected_when_both_order_owners_are_present(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'guest_id' => null,
        ]);

        try {
            DB::table('orders')->where('id', $order->id)->update([
                'guest_id' => (string) Str::uuid(),
            ]);
            $this->fail('The direct update guard did not reject two owners.');
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
