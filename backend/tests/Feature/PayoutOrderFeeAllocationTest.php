<?php

namespace Tests\Feature;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\User;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-B3 (HIGH) — the order-level Stripe fee is deducted once per order and
 * distributed across the line items, never fully per line item.
 */
class PayoutOrderFeeAllocationTest extends TestCase
{
    use RefreshDatabase;

    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService;
    }

    public function test_order_fee_is_deducted_once_across_multiple_items_of_one_photographer(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 300,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web'],
                    ['photoId' => $photo->id, 'price' => 2000, 'tier' => 'print'],
                ],
            ],
            'total_net' => 3000, 'total_gross' => 3600, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmt = PhotographerStatement::where('user_id', $photographer->id)->sole();
        // 3000 total - 300 order fee = 2700 net → 50% = 1350.
        // Buggy per-item deduction would yield (1000-300)*0.5 + (2000-300)*0.5 = 1200.
        $this->assertSame(1350, $stmt->delta_surcharge_earnings_cents);
    }

    public function test_order_fee_is_prorated_across_photographers(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);

        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 300,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoA->id, 'price' => 1000, 'tier' => 'web'],
                    ['photoId' => $photoB->id, 'price' => 2000, 'tier' => 'print'],
                ],
            ],
            'total_net' => 3000, 'total_gross' => 3600, 'tax_rate' => 20.00,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        // A: 1000 - (300 * 1000/3000) = 900 net → 450. B: 2000 - 200 = 1800 net → 900.
        $stmtA = PhotographerStatement::where('user_id', $photographerA->id)->sole();
        $stmtB = PhotographerStatement::where('user_id', $photographerB->id)->sole();
        $this->assertSame(450, $stmtA->delta_surcharge_earnings_cents);
        $this->assertSame(900, $stmtB->delta_surcharge_earnings_cents);
    }
}
