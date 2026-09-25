<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives transient checkout actors a real, signed owner identity.
 *
 * Existing orders are intentionally not backfilled: a both-null row is an
 * ownerless legacy/system row and must remain inaccessible to every customer
 * actor. New rows may carry either a registered user_id or a guest_id, never
 * both. The model repeats this invariant for application writes; database
 * triggers enforce it for direct writes on MySQL/MariaDB and SQLite, while
 * PostgreSQL uses its native CHECK constraint. Each branch is independently
 * retryable after a partially committed schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB auto-commit ALTER TABLE. Keep every schema change
        // independently guarded so a retry can continue after an earlier DDL
        // step was persisted but a later step (for example trigger creation)
        // failed.
        if (! Schema::hasColumn('orders', 'guest_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('guest_id', 36)->nullable()->after('user_id');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_guest_id_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                // Guest order pages and the purchase entitlement lookup always
                // use guest_id plus the explicit user_id IS NULL discriminator.
                $table->index('guest_id', 'orders_guest_id_idx');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_guest_owner_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['guest_id', 'user_id'], 'orders_guest_owner_idx');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_guest_fingerprint_lookup_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                // Keep checkout identity/recovery separate for transient actors;
                // nullable legacy rows do not become a shared owner bucket.
                $table->index(
                    ['guest_id', 'checkout_fingerprint'],
                    'orders_guest_fingerprint_lookup_idx',
                );
            });
        }

        if (! Schema::hasIndex('orders', 'orders_guest_checkout_key_unique', 'unique')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unique(
                    ['guest_id', 'checkout_idempotency_key'],
                    'orders_guest_checkout_key_unique',
                );
            });
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MariaDB 11.4 rejects a table-level owner constraint that references
            // the foreign-key column user_id (SQLSTATE 1901). The owner invariant
            // is still enforced below for direct SQL writes, independently of
            // the Eloquent model guard.
            // DDL auto-commits in MySQL/MariaDB. Drop each trigger before
            // creating it so a retry can recover from a partial trigger run.
            DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_insert');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_insert
                BEFORE INSERT ON orders
                FOR EACH ROW
                BEGIN
                    IF NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'orders_owner_not_both';
                    END IF;
                END
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_update');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_update
                BEFORE UPDATE ON orders
                FOR EACH ROW
                BEGIN
                    IF NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'orders_owner_not_both';
                    END IF;
                END
            SQL);
        } elseif ($driver === 'sqlite') {
            // SQLite cannot add a CHECK constraint with ALTER TABLE. These
            // equivalent triggers preserve the at-most-one-owner invariant for
            // direct writes while allowing both-null legacy rows to remain.
            // Drop each trigger before creating it so a retry can recover if a
            // previous run stopped between the two trigger statements.
            DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_insert');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_insert
                BEFORE INSERT ON orders
                WHEN NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'orders_owner_not_both');
                END
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS orders_owner_not_both_update');
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_update
                BEFORE UPDATE ON orders
                WHEN NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'orders_owner_not_both');
                END
            SQL);
        } elseif ($driver === 'pgsql') {
            // Laravel has no portable Schema::hasCheck() API. The named
            // constraint is nevertheless reliably detectable in PostgreSQL's
            // catalog, so a retry does not attempt to add it a second time.
            // This probe is intentionally PostgreSQL-specific; MariaDB's
            // partial-DDL case is handled by the Schema guards above.
            if (! $this->hasPostgresOwnerConstraint()) {
                DB::statement(
                    'ALTER TABLE orders ADD CONSTRAINT orders_owner_not_both CHECK (user_id IS NULL OR guest_id IS NULL)'
                );
            }
        }
    }

    private function hasPostgresOwnerConstraint(): bool
    {
        return (int) DB::scalar(
            <<<'SQL'
                SELECT COUNT(*)
                FROM pg_constraint
                WHERE conrelid = 'orders'::regclass
                  AND conname = ?
                  AND contype = 'c'
            SQL,
            ['orders_owner_not_both'],
            false,
        ) > 0;
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }
};
