<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Unify payout carry and late-refund corrections in one claimable ledger.
 *
 * Schema: payout_open_items stores a signed amount, its source type/id, the
 * photographer and origin period, plus the period and timestamp where it was
 * settled. The claim indexes are (settled_at, origin_year, origin_month) and
 * (user_id, settled_at, origin_year, origin_month); the settlement lookup
 * index is (settled_year, settled_month). A unique (source_type, source_id)
 * key makes each source produce at most one item.
 *
 * Backfill: every payout_corrections row becomes a refund item with the same
 * source, order, closed period, amount and settlement state. For carry, only a
 * photographer's latest statement overall is considered, and only when it is
 * a non-zero rollover. Older rollover statements have already flowed into
 * later statements and must not be booked again. Statement money is unchanged.
 *
 * Rollback: an executed schema rollback would discard the open-item ledger but
 * would leave payout_corrections and photographer_statements untouched. The
 * repository migration policy does not execute down() methods, so down() is
 * intentionally empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payout_open_items')) {
            Schema::create('payout_open_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('source_type', 16);
                $table->uuid('source_id')->nullable();
                $table->foreignUuid('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
                $table->integer('origin_month');
                $table->integer('origin_year');
                $table->integer('amount_cents');
                $table->integer('settled_month')->nullable();
                $table->integer('settled_year')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();

                $table->unique(['source_type', 'source_id'], 'payout_open_items_source_unique');
                $table->index(
                    ['settled_at', 'origin_year', 'origin_month'],
                    'payout_open_items_claim_period_index',
                );
                $table->index(
                    ['user_id', 'settled_at', 'origin_year', 'origin_month'],
                    'payout_open_items_claim_lookup_index',
                );
                $table->index(
                    ['settled_year', 'settled_month'],
                    'payout_open_items_settlement_lookup_index',
                );
            });
        }

        // Backfills run even when the table predates this attempt: a failed
        // migration may have committed table creation and only part of the data.
        $this->backfillRefunds();
        $this->backfillLatestRollovers();
    }

    private function backfillRefunds(): void
    {
        DB::table('payout_corrections')
            ->orderBy('id')
            ->chunk(500, function ($corrections): void {
                $now = now();
                $items = [];

                foreach ($corrections as $correction) {
                    $items[] = [
                        'id' => (string) Str::uuid(),
                        'user_id' => $correction->user_id,
                        'source_type' => 'refund',
                        'source_id' => $correction->id,
                        'order_id' => $correction->order_id,
                        'origin_month' => $correction->closed_month,
                        'origin_year' => $correction->closed_year,
                        'amount_cents' => $correction->amount_cents,
                        'settled_month' => $correction->settled_month,
                        'settled_year' => $correction->settled_year,
                        'settled_at' => $correction->settled_at,
                        'created_at' => $correction->created_at ?? $now,
                        'updated_at' => $correction->updated_at ?? $now,
                    ];
                }

                if ($items !== []) {
                    DB::table('payout_open_items')->insertOrIgnore($items);
                }
            });
    }

    private function backfillLatestRollovers(): void
    {
        $latestRollovers = DB::table('photographer_statements as ps')
            ->where('ps.status', 'rollover')
            ->where('ps.total_payable_cents', '<>', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('photographer_statements as newer')
                    ->whereColumn('newer.user_id', 'ps.user_id')
                    ->where(function ($query): void {
                        $query->whereColumn('newer.year', '>', 'ps.year')
                            ->orWhere(function ($query): void {
                                $query->whereColumn('newer.year', '=', 'ps.year')
                                    ->whereColumn('newer.month', '>', 'ps.month');
                            });
                    });
            })
            ->orderBy('ps.id')
            ->get([
                'ps.id',
                'ps.user_id',
                'ps.month',
                'ps.year',
                'ps.total_payable_cents',
            ]);

        $now = now();
        foreach ($latestRollovers->chunk(500) as $rollovers) {
            $items = [];
            foreach ($rollovers as $statement) {
                $items[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $statement->user_id,
                    'source_type' => 'carry',
                    'source_id' => $statement->id,
                    'order_id' => null,
                    'origin_month' => $statement->month,
                    'origin_year' => $statement->year,
                    'amount_cents' => $statement->total_payable_cents,
                    'settled_month' => null,
                    'settled_year' => null,
                    'settled_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($items !== []) {
                DB::table('payout_open_items')->insertOrIgnore($items);
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty per backend migration policy.
    }
};
