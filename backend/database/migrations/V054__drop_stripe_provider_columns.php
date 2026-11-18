<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the retired Stripe provider columns from users, orders and payout_pools.
 *
 * Measured empty in production on 2026-11-17 (reproducible command and numbers
 * in features/infrastructure/30-mollie-payments.md): no row carries a Stripe
 * customer id, PaymentIntent id or Stripe fee. The columns are therefore dead
 * schema, not accounting evidence. AD-2 records the schema/backfill/rollback
 * decision. The `users_stripe_customer_id_unique` and
 * `orders_stripe_payment_intent_idx` indexes are dropped first: SQLite refuses
 * to drop an indexed column, while MySQL/MariaDB would drop the index with it.
 *
 * MySQL/MariaDB auto-commit DDL. Every change is guarded with hasIndex() /
 * hasColumn() so a retry after a partially committed run stays a no-op,
 * matching V036's style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('users', 'users_stripe_customer_id_unique', 'unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_stripe_customer_id_unique');
            });
        }
        if (Schema::hasIndex('orders', 'orders_stripe_payment_intent_idx')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_stripe_payment_intent_idx');
            });
        }

        if (Schema::hasColumn('users', 'stripe_customer_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('stripe_customer_id');
            });
        }

        foreach (['stripe_payment_intent_id', 'stripe_fee_cents'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasColumn('payout_pools', 'stripe_fee_cents')) {
            Schema::table('payout_pools', function (Blueprint $table): void {
                $table->dropColumn('stripe_fee_cents');
            });
        }
    }

    public function down(): void
    {
        // Restore the original definitions from V007, V011, V012 and V036.
        // The columns held no production data at drop time, so a rollback
        // recreates empty columns; it cannot restore historical values.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable();
            $table->unique('stripe_customer_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('stripe_payment_intent_id')->nullable();
            $table->index('stripe_payment_intent_id', 'orders_stripe_payment_intent_idx');
            $table->integer('stripe_fee_cents')->nullable();
        });

        Schema::table('payout_pools', function (Blueprint $table): void {
            $table->integer('stripe_fee_cents')->default(0);
        });
    }
};
