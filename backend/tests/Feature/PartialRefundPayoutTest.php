<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\User;
use App\Services\PayoutCalculationService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Finding PAY-5 / issue #27 (owner decision 2026-10-25).
 *
 * A partial refund does not change anything for the customer — the order stays
 * `paid` and keeps its download access — but it does change what the
 * photographer is paid. `orders.refunded_amount_cents` carries Stripe's
 * cumulative `amount_refunded`, and the payout calculation subtracts that
 * amount from the photo revenue in the same proportion as the revenue split.
 */
class PartialRefundPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_partial_refund_payout';

    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService;
        Config::set('services.stripe.webhook_secret', self::WEBHOOK_SECRET);
        Cache::flush();
        Mail::fake();
    }

    /**
     * Create a paid order with one photo line for one photographer.
     *
     * @return array{0: User, 1: Photo, 2: Order}
     */
    private function makePaidOrder(string $paymentIntent, int $priceCents = 5000): array
    {
        $photographer = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id,
        ]);

        $order = Order::factory()->paid()->create([
            'status' => 'paid',
            'stripe_fee_cents' => 0,
            'total_amount' => $priceCents,
            'stripe_payment_intent_id' => $paymentIntent,
            'created_at' => '2026-06-10 12:00:00',
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => $priceCents],
                ],
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => $priceCents],
            ],
            'total_net' => $priceCents,
            'total_gross' => $priceCents,
            'tax_rate' => 0,
        ]);

        return [$photographer, $photo, $order];
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function postRefundEvent(array $charge, string $eventId): void
    {
        $payloadData = [
            'id' => $eventId,
            'type' => 'charge.refunded',
            'data' => ['object' => $charge],
        ];
        $payload = json_encode($payloadData);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => "t={$timestamp},v1={$signature}",
        ])->assertStatus(200);
    }

    public function test_partial_refund_is_stored_and_keeps_paid_status_and_download_access(): void
    {
        [, , $order] = $this->makePaidOrder('pi_partial_persisted', 5000);

        $this->postRefundEvent([
            'payment_intent' => 'pi_partial_persisted',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_partial_persisted');

        $fresh = $order->fresh();
        $this->assertSame(1500, $fresh->refunded_amount_cents);
        $this->assertSame('paid', $fresh->status, 'A partial refund must not revoke the order status.');
        $this->assertTrue(
            app(PurchaseService::class)->isOrderDownloadEligible($fresh),
            'A partial refund must not revoke download access.',
        );
    }

    public function test_full_refund_is_stored_and_transitions_to_refunded(): void
    {
        [, , $order] = $this->makePaidOrder('pi_full_persisted', 5000);

        $this->postRefundEvent([
            'payment_intent' => 'pi_full_persisted',
            'refunded' => true,
            'amount' => 5000,
            'amount_refunded' => 5000,
        ], 'evt_full_persisted');

        $fresh = $order->fresh();
        $this->assertSame(5000, $fresh->refunded_amount_cents);
        $this->assertSame('refunded', $fresh->status);
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($fresh));
    }

    public function test_replayed_older_event_does_not_lower_the_stored_refunded_amount(): void
    {
        [, , $order] = $this->makePaidOrder('pi_partial_replay', 5000);

        $this->postRefundEvent([
            'payment_intent' => 'pi_partial_replay',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_replay_newer');

        $this->assertSame(1500, $order->fresh()->refunded_amount_cents);

        // Stripe's amount_refunded is cumulative. An older/replayed event with
        // a smaller running total must not undo the newer refund.
        $this->postRefundEvent([
            'payment_intent' => 'pi_partial_replay',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 800,
        ], 'evt_replay_older');

        $this->assertSame(1500, $order->fresh()->refunded_amount_cents);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_partial_refund_reduces_each_photographers_statement_in_revenue_proportion(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);

        // Fixture arithmetic: two photo lines of 1000 and 2000 cents, an
        // order-level fee of 0, and a 600-cent partial refund.
        $lineA = 1000;
        $lineB = 2000;
        $refundCents = 600;

        $order = Order::factory()->paid()->create([
            'status' => 'paid',
            'stripe_fee_cents' => 0,
            'total_amount' => $lineA + $lineB,
            'refunded_amount_cents' => $refundCents,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoA->id, 'price' => $lineA, 'tier' => 'web'],
                    ['photoId' => $photoB->id, 'price' => $lineB, 'tier' => 'print'],
                ],
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => $lineA, '1' => $lineB],
            ],
            'total_net' => $lineA + $lineB,
            'total_gross' => $lineA + $lineB,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        // The 600 cents are borne in the revenue ratio 1000:2000, i.e. 200 on
        // A and 400 on B; the photographer share is 50% of what remains.
        $refundOnA = intdiv($refundCents * $lineA, $lineA + $lineB);
        $refundOnB = $refundCents - $refundOnA;
        $expectedA = intdiv($lineA - $refundOnA, 2);
        $expectedB = intdiv($lineB - $refundOnB, 2);

        $this->assertSame(
            $expectedA,
            PhotographerStatement::where('user_id', $photographerA->id)->sole()->delta_surcharge_earnings_cents,
        );
        $this->assertSame(
            $expectedB,
            PhotographerStatement::where('user_id', $photographerB->id)->sole()->delta_surcharge_earnings_cents,
        );
    }

    public function test_order_without_partial_refund_keeps_the_unmodified_statement(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);

        $order = Order::factory()->paid()->create([
            'status' => 'paid',
            'stripe_fee_cents' => 0,
            'total_amount' => 1000,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web'],
                ],
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => 1000],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        // Regression guard: a paid order without a refund still pays exactly
        // 50% of the full photo revenue (1000 → 500).
        $this->assertSame(
            500,
            PhotographerStatement::where('user_id', $photographer->id)->sole()->delta_surcharge_earnings_cents,
        );
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
    }

    public function test_refund_above_order_total_is_clamped_and_never_creates_negative_revenue(): void
    {
        [, , $order] = $this->makePaidOrder('pi_over_refund', 3000);

        // A partial refund whose running total exceeds the recorded order
        // total: the stored value is clamped to total_amount.
        $this->postRefundEvent([
            'payment_intent' => 'pi_over_refund',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 9000,
        ], 'evt_over_refund');

        $this->assertSame(3000, $order->fresh()->refunded_amount_cents);
        $this->assertSame('paid', $order->fresh()->status);

        $this->service->calculatePowerUserDelta(6, 2026);

        // The refund swallows the whole photo revenue: no line goes negative
        // and no statement (in particular none with a negative delta) is
        // booked.
        $this->assertSame(0, PhotographerStatement::query()->count());
    }

    /**
     * This test pins the implemented rule for a locked month, not a wish:
     * `PayoutCalculationService::addStatementDelta()` returns early when the
     * statement is already `approved` or `paid`, so a refund for such a month is
     * not booked at all. The approved statement stays byte-for-byte unchanged.
     */
    public function test_partial_refund_cannot_be_booked_into_an_already_approved_statement(): void
    {
        $photographer = User::factory()->create();
        $photo = Photo::factory()->create(['user_id' => $photographer->id]);

        $statement = PhotographerStatement::create([
            'user_id' => $photographer->id,
            'month' => 6,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 700,
            'earned_amount_cents' => 700,
            'total_payable_cents' => 700,
            'status' => 'approved',
        ]);

        $order = Order::factory()->paid()->create([
            'status' => 'paid',
            'stripe_fee_cents' => 0,
            'total_amount' => 1000,
            'refunded_amount_cents' => 400,
            'created_at' => '2026-06-10 12:00:00',
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'price' => 1000, 'tier' => 'web'],
                ],
                InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY => ['0' => 1000],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => null,
        ]);

        $this->service->calculatePowerUserDelta(6, 2026);

        $fresh = $statement->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame(700, $fresh->delta_surcharge_earnings_cents);
        $this->assertSame(700, $fresh->total_payable_cents);
    }
}
