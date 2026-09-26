<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the card-testing defence columns, uniques, and lookup indexes.
 *
 * MySQL/MariaDB auto-commit DDL. Every change is therefore independently
 * guarded with hasColumn()/hasIndex() so a retry after a partially committed
 * run continues instead of failing on an already existing column/index. This
 * matches the V037+ migration style; the guards make the whole migration
 * idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'stripe_customer_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('stripe_customer_id')->nullable();
            });
        }

        // The unique index is guarded separately from its column: a partial
        // commit that added the column but not the index must be recoverable
        // on a retry instead of being treated as fully applied.
        if (! Schema::hasIndex('users', 'users_stripe_customer_id_unique', 'unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('stripe_customer_id');
            });
        }

        if (! Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('checkout_idempotency_key')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'checkout_fingerprint')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->char('checkout_fingerprint', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'payment_intent_generation')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('payment_intent_generation')->default(1);
            });
        }

        if (! Schema::hasColumn('orders', 'payment_failure_count')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedSmallInteger('payment_failure_count')->default(0);
            });
        }

        if (! Schema::hasColumn('orders', 'last_payment_failure_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('last_payment_failure_at')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'last_payment_decline_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('last_payment_decline_code')->nullable();
            });
        }

        if (! Schema::hasIndex('orders', 'orders_user_id_checkout_idempotency_key_unique', 'unique')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique(['user_id', 'checkout_idempotency_key']);
            });
        }

        if (! Schema::hasIndex('orders', 'orders_stripe_payment_intent_idx')) {
            Schema::table('orders', function (Blueprint $table) {
                // Supports PaymentIntent lookup for webhook handling and identity-guarded updates.
                $table->index('stripe_payment_intent_id', 'orders_stripe_payment_intent_idx');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_user_fingerprint_lookup_idx')) {
            Schema::table('orders', function (Blueprint $table) {
                // Supports the browser-refresh fallback when the opaque client key
                // is lost. It is intentionally non-unique; the key uniqueness
                // constraint above remains the authoritative session identity.
                $table->index(['user_id', 'checkout_fingerprint'], 'orders_user_fingerprint_lookup_idx');
            });
        }

        if (! Schema::hasIndex('orders', 'orders_pending_stale_idx')) {
            Schema::table('orders', function (Blueprint $table) {
                // Supports stale-PI selection, cutoff ordering, and deterministic ID tie-breaking.
                $table->index(['status', 'created_at', 'id'], 'orders_pending_stale_idx');
            });
        }
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }
};
