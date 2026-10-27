<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve each order's photographer share as it was included in a month close.
 *
 * Schema: one row per (order, photographer, year, month), linked to the source
 * order, photographer, and optionally the statement that received the share.
 * The row records the booked share, the remaining photo-backed revenue used as
 * its proportional-refund basis, and the cumulative refund already observed.
 *
 * Backfill: intentionally empty. Historical statements do not contain their
 * per-order photographer shares, and reconstructing them from current photos
 * would invent historical facts. Statements booked before V052 keep their
 * stored money unchanged and have no booking row; their refund corrections
 * retain the current snapshot/photo recomputation fallback.
 *
 * Rollback: dropping this table would lose booking evidence while leaving all
 * statements and corrections unchanged. The repository migration policy does
 * not execute down() methods, so down() is intentionally empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payout_order_bookings')) {
            return;
        }

        Schema::create('payout_order_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('statement_id')->nullable()->constrained('photographer_statements')->nullOnDelete();
            $table->integer('month');
            $table->integer('year');
            $table->integer('share_cents');
            $table->integer('photo_revenue_cents');
            $table->integer('refunded_cents')->default(0);
            $table->timestamps();

            $table->unique(
                ['order_id', 'user_id', 'year', 'month'],
                'payout_order_bookings_order_user_period_unique',
            );
            $table->index(
                ['user_id', 'year', 'month'],
                'payout_order_bookings_user_period_index',
            );
        });
    }

    public function down(): void
    {
        // Intentionally empty per backend migration policy.
    }
};
