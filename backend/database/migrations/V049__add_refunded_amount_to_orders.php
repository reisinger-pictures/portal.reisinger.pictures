<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The refunded amount becomes a persisted order fact.
 *
 * Owner decision 2026-10-25 (GitHub issue #27 / finding PAY-5): a partial refund
 * changes nothing for the customer — the order stays `paid` and the download
 * access stays granted — but it does change what the photographer is paid.
 * Before this column existed, `charge.refunded` only branched on full vs.
 * partial: a partial refund was logged and then dropped, so the refunded money
 * stayed in the revenue base of the payout calculation and the photographer was
 * paid on money the platform no longer held.
 *
 * Schema decision, documented up front as required by backend/AGENTS.md:
 * - `refunded_amount_cents` is an unsigned integer with default 0. It stores
 *   Stripe's cumulative `charge.amount_refunded`, not a single refund event:
 *   several partial refunds of the same charge are one running total.
 * - It is deliberately NOT derived from `orders.total_amount` or from the
 *   invoice snapshot. Stripe's cumulative amount is the only value that stays
 *   correct through a partial refund sequence, and the webhook clamps it to
 *   `0 … total_amount` before writing.
 * - No index: the payout path reads the column for orders it already selected
 *   by month and status, never by the column itself.
 *
 * Backfill: the column default (0) is the backfill. A historical partial refund
 * cannot be reconstructed here — the information lives only at Stripe and the
 * charge identity of a settled order may no longer resolve (a missing
 * `stripe_payment_intent_id` has no lookup path inside a migration). Historical
 * full refunds need no value: an order with status `refunded` is not part of
 * the payout selection (`PayoutCalculationService::calculatePowerUserDelta()`
 * selects `status = 'paid'`), so the amount only matters for partial refunds
 * observed after this migration. Inventing `refunded_amount_cents = total_amount`
 * for legacy `refunded` rows would persist a number no Stripe event ever
 * reported, which is worse than an explicit 0 for a row the payout ignores.
 *
 * Rollback: `down()` drops the column and nothing else. The pre-migration state
 * is a tree without the field; the refund provenance of refunds received after
 * the migration is then lost, which is the documented cost of rolling the
 * persisted refund fact back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'refunded_amount_cents')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unsignedInteger('refunded_amount_cents')
                    ->default(0)
                    ->after('stripe_fee_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'refunded_amount_cents')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('refunded_amount_cents');
            });
        }
    }
};
