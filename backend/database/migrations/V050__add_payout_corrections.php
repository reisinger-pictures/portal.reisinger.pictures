<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist late-refund payout corrections independently from immutable statements.
 *
 * D-58 requires refunds received after an approved/paid month's statement to
 * appear as negative lines in a later finalized statement. Corrections have no
 * target period: each finalization claims unsettled rows from earlier closed
 * periods and records the period in which each line was booked. Existing
 * statement aggregates do not record per-order shares or when a refund was
 * received, so historical corrections cannot be reconstructed safely. The
 * backfill is therefore intentionally empty; new webhook observations create
 * the rows.
 *
 * Rolling back drops the correction ledger and loses its pending/settled audit
 * records. No statement is rewritten by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payout_corrections')) {
            return;
        }

        Schema::create('payout_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('refund_amount_cents');
            $table->integer('amount_cents');
            $table->integer('closed_month');
            $table->integer('closed_year');
            $table->integer('settled_month')->nullable();
            $table->integer('settled_year')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_id', 'user_id', 'closed_year', 'closed_month', 'refund_amount_cents'],
                'payout_corrections_refund_identity_unique',
            );
            $table->index(
                ['settled_at', 'closed_year', 'closed_month'],
                'payout_corrections_claim_lookup_index',
            );
            $table->index(
                ['user_id', 'settled_month', 'settled_year'],
                'payout_corrections_statement_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_corrections');
    }
};
