<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable ledger for one Mollie payment attempt per order generation.
 *
 * Schema decision (owner-assigned GitHub issue #53, contract in
 * features/infrastructure/30-mollie-payments.md): the order columns cannot
 * represent more than one provider attempt, cannot distinguish an unknown
 * remote create from a completed one, and cannot enforce provider-ID
 * uniqueness. This table therefore stores one row per attempt:
 *
 * - `id` is the attempt UUID and doubles as the client-visible attempt
 *   identity; `idempotency_key` is the separate, unique Mollie Idempotency-Key.
 * - `(order_id, generation)` is unique and is the retry boundary: a retry
 *   resumes the same attempt, a verified terminal failure moves to the next
 *   generation.
 * - `mollie_payment_id` is nullable and unique. Null means "a create may have
 *   reached Mollie but its answer was lost"; the attempt is then ambiguous and
 *   is never silently replaced.
 * - `snapshot` freezes the server-priced gross amount, currency, order
 *   reference and withdrawal-consent evidence before the provider call.
 *
 * Backfill: intentionally empty. The table is exclusively for newly created
 * Mollie attempts; all Stripe orders and PaymentIntents stay untouched in the
 * existing order/PaymentIntent records.
 *
 * Rollback: intentionally empty. Dropping an attempt ledger could erase
 * financial reconciliation evidence, and the repository migration policy does
 * not execute down() methods. Rolling the application code back leaves this
 * additive table unused and changes no invoice, order, or Stripe data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mollie_payment_attempts')) {
            return;
        }

        Schema::create('mollie_payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('generation');
            $table->uuid('idempotency_key');
            $table->string('mollie_payment_id', 64)->nullable();
            $table->string('mode', 8);
            $table->string('credential_fingerprint', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 24)->default('creating');
            $table->text('checkout_url')->nullable();
            $table->json('snapshot');
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'generation'], 'mollie_payment_attempts_order_generation_unique');
            $table->unique('idempotency_key', 'mollie_payment_attempts_idempotency_key_unique');
            $table->unique('mollie_payment_id', 'mollie_payment_attempts_payment_id_unique');
            $table->index(['status', 'last_reconciled_at'], 'mollie_payment_attempts_status_index');
        });
    }

    public function down(): void
    {
        // Intentionally empty per backend migration policy.
    }
};
