<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PayoutCorrection;
use App\Models\PayoutOpenItem;
use App\Models\PhotographerStatement;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayoutMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v051_backfills_refunds_and_only_the_latest_open_rollover(): void
    {
        $carryPhotographer = User::factory()->create();
        foreach ([
            [10, 2025, 1000],
            [11, 2025, 2000],
            [12, 2025, 3000],
        ] as [$month, $year, $payable]) {
            PhotographerStatement::query()->create([
                'user_id' => $carryPhotographer->id,
                'month' => $month,
                'year' => $year,
                'status' => 'rollover',
                'total_payable_cents' => $payable,
            ]);
        }

        $paidPhotographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $paidPhotographer->id,
            'month' => 10,
            'year' => 2025,
            'status' => 'rollover',
            'total_payable_cents' => 2500,
        ]);
        PhotographerStatement::query()->create([
            'user_id' => $paidPhotographer->id,
            'month' => 11,
            'year' => 2025,
            'status' => 'paid',
            'total_payable_cents' => 6000,
        ]);

        $pendingPhotographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $pendingPhotographer->id,
            'month' => 12,
            'year' => 2025,
            'status' => 'pending',
            'total_payable_cents' => 5000,
        ]);

        $approvedPhotographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $approvedPhotographer->id,
            'month' => 12,
            'year' => 2025,
            'status' => 'approved',
            'total_payable_cents' => 6000,
        ]);

        $zeroRolloverPhotographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $zeroRolloverPhotographer->id,
            'month' => 12,
            'year' => 2025,
            'status' => 'rollover',
            'total_payable_cents' => 0,
        ]);

        $pendingOrder = Order::factory()->paid()->create();
        $pendingCorrection = PayoutCorrection::query()->create([
            'order_id' => $pendingOrder->id,
            'user_id' => $pendingPhotographer->id,
            'refund_amount_cents' => 500,
            'amount_cents' => -250,
            'closed_month' => 9,
            'closed_year' => 2025,
        ]);

        $settledOrder = Order::factory()->paid()->create();
        $settledAt = now()->subDay()->startOfSecond();
        $settledCorrection = PayoutCorrection::query()->create([
            'order_id' => $settledOrder->id,
            'user_id' => $paidPhotographer->id,
            'refund_amount_cents' => 1000,
            'amount_cents' => -500,
            'closed_month' => 10,
            'closed_year' => 2025,
            'settled_at' => $settledAt,
            'settled_month' => 12,
            'settled_year' => 2025,
        ]);

        // RefreshDatabase has already applied V051 to an empty test schema.
        // Recreate its pre-migration state and invoke the real migration again.
        Schema::drop('payout_open_items');
        $migration = require database_path('migrations/V051__create_payout_open_items.php');
        $migration->up();

        // Simulate a process stop after only part of the backfill committed.
        // The retry must restore missing sources and ignore those already copied.
        PayoutOpenItem::query()
            ->where('source_type', 'refund')
            ->where('source_id', $pendingCorrection->id)
            ->delete();
        PayoutOpenItem::query()
            ->where('source_type', 'carry')
            ->delete();
        $migration->up();
        $migration->up();

        $indexColumns = collect(Schema::getIndexes('payout_open_items'))
            ->map(fn (array $index): array => array_map('strtolower', $index['columns']))
            ->all();
        $this->assertContains(['settled_at', 'origin_year', 'origin_month'], $indexColumns);
        $this->assertContains(
            ['user_id', 'settled_at', 'origin_year', 'origin_month'],
            $indexColumns,
        );
        $uniqueIndexColumns = collect(Schema::getIndexes('payout_open_items'))
            ->filter(fn (array $index): bool => $index['unique'])
            ->map(fn (array $index): array => array_map('strtolower', $index['columns']))
            ->all();
        $this->assertContains(['source_type', 'source_id'], $uniqueIndexColumns);

        $refundItems = PayoutOpenItem::query()->where('source_type', 'refund')->get()->keyBy('source_id');
        $this->assertCount(2, $refundItems);
        $this->assertSame(3, PayoutOpenItem::query()->count());
        $this->assertSame((string) $pendingCorrection->id, (string) $refundItems[$pendingCorrection->id]->source_id);
        $this->assertSame((string) $pendingCorrection->order_id, (string) $refundItems[$pendingCorrection->id]->order_id);
        $this->assertSame(-250, $refundItems[$pendingCorrection->id]->amount_cents);
        $this->assertSame(9, $refundItems[$pendingCorrection->id]->origin_month);
        $this->assertSame(2025, $refundItems[$pendingCorrection->id]->origin_year);
        $this->assertNull($refundItems[$pendingCorrection->id]->settled_at);
        $this->assertSame((string) $settledCorrection->id, (string) $refundItems[$settledCorrection->id]->source_id);
        $this->assertSame(-500, $refundItems[$settledCorrection->id]->amount_cents);
        $this->assertSame(12, $refundItems[$settledCorrection->id]->settled_month);
        $this->assertSame(2025, $refundItems[$settledCorrection->id]->settled_year);
        $this->assertSame($settledAt->toDateTimeString(), $refundItems[$settledCorrection->id]->settled_at->toDateTimeString());

        $carryItems = PayoutOpenItem::query()->where('source_type', 'carry')->get();
        $carry = $carryItems->sole();
        $latestRollover = PhotographerStatement::query()
            ->where('user_id', $carryPhotographer->id)
            ->where('month', 12)
            ->where('year', 2025)
            ->sole();
        $this->assertSame((string) $latestRollover->id, (string) $carry->source_id);
        $this->assertSame(3000, $carry->amount_cents);
        $this->assertSame(12, $carry->origin_month);
        $this->assertSame(2025, $carry->origin_year);
        $this->assertNull($carry->settled_at);
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $paidPhotographer->id,
            'source_type' => 'carry',
        ]);
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $pendingPhotographer->id,
            'source_type' => 'carry',
        ]);
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $approvedPhotographer->id,
            'source_type' => 'carry',
        ]);
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $zeroRolloverPhotographer->id,
            'source_type' => 'carry',
        ]);

        PhotographerStatement::query()->create([
            'user_id' => $carryPhotographer->id,
            'month' => 1,
            'year' => 2026,
            'pool_earnings_cents' => 0,
        ]);
        app(PayoutCalculationService::class)->finalizeStatements(1, 2026);
        $january = PhotographerStatement::query()
            ->where('user_id', $carryPhotographer->id)
            ->where('month', 1)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(3000, $january->rolled_over_amount_cents);
        $this->assertSame(3000, $january->total_payable_cents);
    }
}
