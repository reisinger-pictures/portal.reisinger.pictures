<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_idempotency_key')->nullable();
            $table->char('checkout_fingerprint', 64)->nullable();
            $table->unsignedInteger('payment_intent_generation')->default(1);
            $table->unsignedSmallInteger('payment_failure_count')->default(0);
            $table->timestamp('last_payment_failure_at')->nullable();
            $table->string('last_payment_decline_code')->nullable();
            $table->unique(['user_id', 'checkout_idempotency_key']);
            // Supports PaymentIntent lookup for webhook handling and identity-guarded updates.
            $table->index('stripe_payment_intent_id', 'orders_stripe_payment_intent_idx');
            // Supports the browser-refresh fallback when the opaque client key
            // is lost. It is intentionally non-unique; the key uniqueness
            // constraint above remains the authoritative session identity.
            $table->index(['user_id', 'checkout_fingerprint'], 'orders_user_fingerprint_lookup_idx');
            // Supports stale-PI selection, cutoff ordering, and deterministic ID tie-breaking.
            $table->index(['status', 'created_at', 'id'], 'orders_pending_stale_idx');
        });
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }
};
