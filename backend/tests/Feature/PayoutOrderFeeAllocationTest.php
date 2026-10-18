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

    public function test_fully_discounted_paid_order_does_not_create_photographer_delta(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 0,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web'],
                    [
                        'type' => 'discount_coupon',
                        'price' => -1000,
                        'qty' => 1,
                        'row_total' => -1000,
                    ],
                ],
            ],
            'total_net' => 0,
            'total_gross' => 0,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame(0, PhotographerStatement::where('user_id', $photographer->id)->count());
    }

    public function test_partial_coupon_discount_and_order_fee_are_allocated_before_photographer_share(): void
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
                    [
                        'type' => 'discount_coupon',
                        'price' => -300,
                        'qty' => 1,
                        'row_total' => -300,
                    ],
                ],
            ],
            'total_net' => 2700,
            'total_gross' => 2700,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmtA = PhotographerStatement::where('user_id', $photographerA->id)->sole();
        $stmtB = PhotographerStatement::where('user_id', $photographerB->id)->sole();
        // Net photo revenue is allocated 900/1800 after the 300-cent coupon;
        // the 300-cent Stripe fee is then allocated 100/200.
        $this->assertSame(400, $stmtA->delta_surcharge_earnings_cents);
        $this->assertSame(800, $stmtB->delta_surcharge_earnings_cents);
    }

    public function test_volume_tier_discount_is_excluded_from_photographer_delta(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 0,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoA->id, 'price' => 1000, 'tier' => 'original'],
                    ['photoId' => $photoB->id, 'price' => 1000, 'tier' => 'original'],
                    [
                        'type' => 'discount_fixed',
                        'price' => -300,
                        'qty' => 2,
                        'row_total' => -600,
                    ],
                ],
            ],
            'total_net' => 1400,
            'total_gross' => 1400,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $stmtA = PhotographerStatement::where('user_id', $photographerA->id)->sole();
        $stmtB = PhotographerStatement::where('user_id', $photographerB->id)->sole();
        $this->assertSame(350, $stmtA->delta_surcharge_earnings_cents);
        $this->assertSame(350, $stmtB->delta_surcharge_earnings_cents);
    }

    public function test_explicit_photo_revenue_drives_fee_allocation_instead_of_gross_lines(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 150,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoA->id, 'price' => 1000, 'tier' => 'web'],
                    ['photoId' => $photoB->id, 'price' => 1000, 'tier' => 'web'],
                    [
                        'type' => 'discount_coupon',
                        'price' => -500,
                        'qty' => 1,
                        'row_total' => -500,
                    ],
                ],
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => 500, '1' => 1000],
            ],
            'total_net' => 1500,
            'total_gross' => 1500,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        // The order fee is 150 * (500/1500) = 50 for A and 100 for B.
        // A: (500 - 50) * 50% = 225; B: (1000 - 100) * 50% = 450.
        $this->assertSame(225, PhotographerStatement::where('user_id', $photographerA->id)->sole()->delta_surcharge_earnings_cents);
        $this->assertSame(450, PhotographerStatement::where('user_id', $photographerB->id)->sole()->delta_surcharge_earnings_cents);
    }

    public function test_payout_map_above_total_net_falls_back_to_proportional_allocation(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 0,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoA->id, 'price' => 1000, 'tier' => 'web'],
                    ['photoId' => $photoB->id, 'price' => 1000, 'tier' => 'web'],
                ],
                // Deliberately inconsistent map: 900 + 900 = 1800 > 1500.
                // The per-line values are individually plausible (≤ line price),
                // so only the sum guard catches this snapshot.
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => 900, '1' => 900],
            ],
            'total_net' => 1500,
            'total_gross' => 1500,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        // Fallback: the recorded 1500 collected cents are split proportionally
        // over the 1000/1000 gross lines → 750 each → 375 each at 50%.
        // Trusting the map would pay 900 each → 450 each and exceed the
        // collectible amount.
        $this->assertSame(375, PhotographerStatement::where('user_id', $photographerA->id)->sole()->delta_surcharge_earnings_cents);
        $this->assertSame(375, PhotographerStatement::where('user_id', $photographerB->id)->sole()->delta_surcharge_earnings_cents);
    }

    public function test_fee_rounding_never_allocates_more_than_each_line_revenue(): void
    {
        $photographers = User::factory()->count(3)->create();
        $photos = $photographers->map(fn (User $photographer): Photo => Photo::factory()->create([
            'user_id' => $photographer->id,
        ]));
        $order = Order::factory()->paid()->create([
            'stripe_fee_cents' => 1,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => $photos->map(fn (Photo $photo): array => [
                    'photoId' => $photo->id,
                    'price' => 1000,
                    'tier' => 'web',
                ])->all(),
            ],
            'total_net' => 3000,
            'total_gross' => 3000,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $deltas = $photographers->map(
            fn (User $photographer): int => PhotographerStatement::where('user_id', $photographer->id)->sole()->delta_surcharge_earnings_cents,
        )->sort()->values()->all();
        $this->assertSame([499, 500, 500], $deltas);
        $this->assertLessThanOrEqual(1499, array_sum($deltas));
    }
}
