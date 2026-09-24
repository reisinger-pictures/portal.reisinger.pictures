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
 * both. The model repeats this invariant for SQLite and for code paths that
 * bypass the database constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('guest_id', 36)->nullable()->after('user_id');

            // Guest order pages and the purchase entitlement lookup always use
            // guest_id plus the explicit user_id IS NULL discriminator.
            $table->index('guest_id', 'orders_guest_id_idx');
            $table->index(['guest_id', 'user_id'], 'orders_guest_owner_idx');

            // Keep checkout identity/recovery separate for transient actors;
            // nullable legacy rows do not become a shared owner bucket.
            $table->index(
                ['guest_id', 'checkout_fingerprint'],
                'orders_guest_fingerprint_lookup_idx',
            );
            $table->unique(
                ['guest_id', 'checkout_idempotency_key'],
                'orders_guest_checkout_key_unique',
            );
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_owner_not_both CHECK (user_id IS NULL OR guest_id IS NULL)'
            );
        } elseif ($driver === 'sqlite') {
            // SQLite cannot add a CHECK constraint with ALTER TABLE. These
            // equivalent triggers preserve the at-most-one-owner invariant for
            // direct writes while allowing both-null legacy rows to remain.
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_insert
                BEFORE INSERT ON orders
                WHEN NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'orders_owner_not_both');
                END
            SQL);
            DB::statement(<<<'SQL'
                CREATE TRIGGER orders_owner_not_both_update
                BEFORE UPDATE ON orders
                WHEN NEW.user_id IS NOT NULL AND NEW.guest_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'orders_owner_not_both');
                END
            SQL);
        } elseif ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_owner_not_both CHECK (user_id IS NULL OR guest_id IS NULL)'
            );
        }
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }
};
