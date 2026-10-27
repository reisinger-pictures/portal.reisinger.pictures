<?php

namespace Tests\Feature;

use App\Http\Resources\PhotographerStatementResource;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\PayoutCorrection;
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
 * PAY-5 / PAY-6 (owner decisions D-58 and 2026-10-25).
 *
 * A partial refund does not change anything for the customer — the order stays
 * `paid` and keeps its download access — but it does change what the
 * photographer is paid. `orders.refunded_amount_cents` carries Stripe's
 * cumulative `amount_refunded`, and the payout calculation subtracts that
 * amount from the photo revenue in the same proportion as the revenue split.
 * A refund after statement close is carried as an explicit negative correction
 * into the next finalization after the originating period.
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
    private function makePaidOrder(
        string $paymentIntent,
        int $priceCents = 5000,
        string $createdAt = '2026-06-10 12:00:00',
    ): array {
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
            'created_at' => $createdAt,
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

    private function approveJuneStatement(User $photographer): PhotographerStatement
    {
        return $this->approveMonthStatement($photographer, 6);
    }

    private function approveMonthStatement(User $photographer, int $month): PhotographerStatement
    {
        $this->service->calculatePowerUserDelta($month, 2026);
        $this->service->finalizeStatements($month, 2026);

        $statement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', $month)
            ->where('year', 2026)
            ->sole();
        $statement->update(['status' => 'approved']);

        return $statement->fresh();
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

    public function test_partial_refund_cannot_be_booked_into_an_already_approved_statement(): void
    {
        [$photographer, , $order] = $this->makePaidOrder('pi_late_partial');
        $statement = $this->approveJuneStatement($photographer);
        $bookedTotal = $statement->total_payable_cents;

        $this->postRefundEvent([
            'payment_intent' => 'pi_late_partial',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_late_partial');

        $correction = PayoutCorrection::query()->sole();
        $this->assertSame((string) $order->id, (string) $correction->order_id);
        $this->assertSame((string) $photographer->id, (string) $correction->user_id);
        $this->assertSame(-750, $correction->amount_cents);
        $this->assertSame(6, $correction->closed_month);
        $this->assertSame(2026, $correction->closed_year);
        $this->assertNull($correction->settled_month);
        $this->assertNull($correction->settled_year);
        $this->assertNull($correction->settled_at);

        $this->assertSame('approved', $statement->fresh()->status);
        $this->assertSame($bookedTotal, $statement->fresh()->total_payable_cents);

        $this->service->finalizeStatements(7, 2026);

        $nextStatement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-750, $nextStatement->earned_amount_cents);
        $this->assertSame(-750, $nextStatement->total_payable_cents);
        $this->assertSame(7, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);

        $resource = (new PhotographerStatementResource($nextStatement))->resolve(request());
        $this->assertSame(1, count($resource['corrections']));
        $this->assertSame(-750, $resource['corrections'][0]['amount_cents']);
        $this->assertSame(6, $resource['corrections'][0]['closed_month']);
        $this->assertSame(7, $resource['corrections'][0]['settled_month']);
        $this->assertSame(2026, $resource['corrections'][0]['settled_year']);
        $this->assertArrayNotHasKey('month', $resource['corrections'][0]);
        $this->assertSame((string) $order->id, $resource['corrections'][0]['order_id']);
    }

    public function test_late_full_refund_reverses_the_share_booked_in_the_closed_month(): void
    {
        [$photographer, , $order] = $this->makePaidOrder('pi_late_full');
        $statement = $this->approveJuneStatement($photographer);
        $bookedShare = $statement->delta_surcharge_earnings_cents;

        $this->postRefundEvent([
            'payment_intent' => 'pi_late_full',
            'refunded' => true,
            'amount' => 5000,
            'amount_refunded' => 5000,
        ], 'evt_late_full');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(-$bookedShare, PayoutCorrection::query()->sole()->amount_cents);

        $this->service->finalizeStatements(7, 2026);

        $nextStatement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-$bookedShare, $nextStatement->total_payable_cents);
    }

    public function test_negative_correction_carries_across_a_missing_statement_month(): void
    {
        [$photographer] = $this->makePaidOrder('pi_late_gap_missing');
        $this->approveJuneStatement($photographer);

        $this->postRefundEvent([
            'payment_intent' => 'pi_late_gap_missing',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_late_gap_missing');
        $this->service->finalizeStatements(7, 2026);

        $this->assertDatabaseMissing('photographer_statements', [
            'user_id' => $photographer->id,
            'month' => 8,
            'year' => 2026,
        ]);
        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 9,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);

        $this->service->finalizeStatements(9, 2026);
        $this->service->finalizeStatements(9, 2026);

        $september = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 9)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-750, $september->rolled_over_amount_cents);
        $this->assertSame(-750, $september->total_payable_cents);
    }

    public function test_zero_balance_after_negative_correction_stops_the_older_rollover(): void
    {
        [$photographer] = $this->makePaidOrder('pi_late_gap_zero');
        $this->approveJuneStatement($photographer);

        $this->postRefundEvent([
            'payment_intent' => 'pi_late_gap_zero',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_late_gap_zero');
        $this->service->finalizeStatements(7, 2026);

        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 8,
            'year' => 2026,
            'pool_earnings_cents' => 750,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-750, $august->rolled_over_amount_cents);
        $this->assertSame(0, $august->total_payable_cents);

        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 9,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);

        $this->service->finalizeStatements(9, 2026);

        $september = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 9)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(0, $september->rolled_over_amount_cents);
        $this->assertSame(0, $september->total_payable_cents);
        $this->assertSame('rollover', $september->status);
    }

    public function test_zero_balance_after_positive_rollover_stops_the_older_rollover(): void
    {
        [$photographer, , $order] = $this->makePaidOrder('pi_positive_rollover_zero');
        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'approved',
        ]);
        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 7,
            'year' => 2026,
            'pool_earnings_cents' => 750,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(7, 2026);

        PayoutCorrection::query()->create([
            'order_id' => $order->id,
            'user_id' => $photographer->id,
            'refund_amount_cents' => 1500,
            'amount_cents' => -750,
            'closed_month' => 6,
            'closed_year' => 2026,
        ]);
        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 8,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(750, $august->rolled_over_amount_cents);
        $this->assertSame(0, $august->total_payable_cents);

        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 9,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(9, 2026);

        $september = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 9)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(0, $september->rolled_over_amount_cents);
        $this->assertSame(0, $september->total_payable_cents);
    }

    public function test_replayed_webhook_and_recalculation_book_a_correction_once(): void
    {
        [$photographer] = $this->makePaidOrder('pi_late_replay');
        $this->approveJuneStatement($photographer);
        $charge = [
            'payment_intent' => 'pi_late_replay',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ];

        $this->postRefundEvent($charge, 'evt_late_replay');
        $this->postRefundEvent($charge, 'evt_late_replay');
        $this->assertSame(1, PayoutCorrection::query()->count());

        $this->service->finalizeStatements(7, 2026);
        $this->service->finalizeStatements(7, 2026);
        $this->assertSame(-750, PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole()
            ->total_payable_cents);

        // The real calculate endpoint replaces open statements before it
        // finalizes again. Replaying that calculation must retain, not duplicate,
        // already-settled correction lines.
        PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->delete();
        $this->service->calculatePowerUserDelta(7, 2026);
        $this->service->finalizeStatements(7, 2026);

        $this->assertSame(-750, PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole()
            ->total_payable_cents);
        $this->assertNotNull(PayoutCorrection::query()->sole()->settled_at);
    }

    public function test_late_correction_never_mutates_an_approved_statement(): void
    {
        [$photographer] = $this->makePaidOrder('pi_immutable_approved');
        $statement = $this->approveJuneStatement($photographer);
        $original = $statement->only([
            'status',
            'pool_earnings_cents',
            'delta_surcharge_earnings_cents',
            'earned_amount_cents',
            'rolled_over_amount_cents',
            'total_payable_cents',
        ]);

        $this->postRefundEvent([
            'payment_intent' => 'pi_immutable_approved',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_immutable_approved');
        $this->service->calculatePowerUserDelta(6, 2026);
        $this->service->finalizeStatements(6, 2026);

        $this->assertSame($original, $statement->fresh()->only(array_keys($original)));
        $this->assertSame(1, PayoutCorrection::query()->count());
    }

    public function test_correction_created_after_next_month_finalized_is_claimed_by_following_finalize(): void
    {
        [$photographer] = $this->makePaidOrder('pi_correction_waits');
        $this->approveJuneStatement($photographer);

        $otherPhotographer = User::factory()->create();
        PhotographerStatement::query()->create([
            'user_id' => $otherPhotographer->id,
            'month' => 7,
            'year' => 2026,
            'pool_earnings_cents' => 0,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(7, 2026);

        $this->postRefundEvent([
            'payment_intent' => 'pi_correction_waits',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_correction_waits');

        $correction = PayoutCorrection::query()->sole();
        $this->assertNull($correction->settled_at);
        $this->assertNull($correction->settled_month);
        $this->assertNull($correction->settled_year);
        $this->assertSame(0, PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->count());

        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-750, $august->earned_amount_cents);
        $this->assertSame(-750, $august->total_payable_cents);
        $this->assertSame(8, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);

        $this->service->finalizeStatements(8, 2026);
        $this->assertSame(-750, $august->fresh()->earned_amount_cents);
        $this->assertSame(-750, $august->fresh()->total_payable_cents);
        $this->assertSame(1, PayoutCorrection::query()->count());
    }

    public function test_correction_skips_approved_statement_and_is_claimed_in_the_following_period(): void
    {
        [$photographer] = $this->makePaidOrder('pi_correction_approved_month');
        $this->approveJuneStatement($photographer);
        $this->postRefundEvent([
            'payment_intent' => 'pi_correction_approved_month',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ], 'evt_correction_approved_month');

        $approvedJuly = PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 7,
            'year' => 2026,
            'pool_earnings_cents' => 6000,
            'delta_surcharge_earnings_cents' => 200,
            'earned_amount_cents' => 6200,
            'rolled_over_amount_cents' => 0,
            'total_payable_cents' => 6200,
            'status' => 'approved',
        ]);
        $approvedAmounts = $approvedJuly->only([
            'pool_earnings_cents',
            'delta_surcharge_earnings_cents',
            'earned_amount_cents',
            'rolled_over_amount_cents',
            'total_payable_cents',
            'status',
        ]);
        $correction = PayoutCorrection::query()->sole();

        $this->service->finalizeStatements(7, 2026);

        $this->assertSame($approvedAmounts, $approvedJuly->fresh()->only(array_keys($approvedAmounts)));
        $this->assertNull($correction->fresh()->settled_at);
        $this->assertNull($correction->fresh()->settled_month);
        $this->assertNull($correction->fresh()->settled_year);

        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-750, $august->earned_amount_cents);
        $this->assertSame(-750, $august->total_payable_cents);
        $this->assertSame(8, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);
    }
}
