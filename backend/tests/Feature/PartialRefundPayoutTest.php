<?php

namespace Tests\Feature;

use App\Http\Resources\PhotographerStatementResource;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\PayoutCorrection;
use App\Models\PayoutOpenItem;
use App\Models\PayoutOrderBooking;
use App\Models\Photo;
use App\Models\PhotographerStatement;
use App\Models\User;
use App\Services\MollieCheckoutService;
use App\Services\PayoutCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAY-5 / PAY-6 (owner decisions D-58 and 2026-10-25).
 *
 * A partial refund does not change anything for the customer — the order stays
 * `paid` and keeps its download access — but it does change what the
 * photographer is paid. `orders.refunded_amount_cents` carries the cumulative
 * refunded amount, and the payout calculation subtracts that amount from the
 * photo revenue in the same proportion as the revenue split. A refund after
 * statement close is carried as an explicit negative correction into the next
 * finalization after the originating period.
 *
 * The provider ingestion is exercised through the Mollie ingress seam
 * (`MollieCheckoutService::applyVerifiedPayment()`), which is the same path a
 * verified Mollie webhook takes: it writes the cumulative
 * `orders.refunded_amount_cents` and hands the newly observed amount to the
 * payout side (`recordLateRefundCorrections`) in one transaction.
 */
class PartialRefundPayoutTest extends TestCase
{
    use RefreshDatabase;

    private PayoutCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayoutCalculationService;
        Cache::flush();
        Mail::fake();
    }

    /**
     * Create a paid order with one photo line for one photographer.
     *
     * @return array{0: User, 1: Photo, 2: Order}
     */
    private function makePaidOrder(
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
            'total_amount' => $priceCents,
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

        $this->createPaidAttempt($order, $priceCents);

        return [$photographer, $photo, $order];
    }

    private function createPaidAttempt(Order $order, int $amountCents): MolliePaymentAttempt
    {
        return MolliePaymentAttempt::create([
            'order_id' => (string) $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => 'tr_refund'.Str::lower(Str::random(10)),
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'status' => MolliePaymentAttempt::STATUS_PAID,
            'checkout_url' => 'https://www.mollie.com/checkout/select-method/tr_refund',
            'snapshot' => ['gross_cents' => $amountCents, 'currency' => 'EUR'],
            'paid_at' => now(),
        ]);
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
        PayoutOpenItem::query()
            ->where('source_type', 'carry')
            ->where('source_id', $statement->id)
            ->delete();

        return $statement->fresh();
    }

    /**
     * Deliver a verified refund observation through the real Mollie ingress
     * seam. The cumulative provider amount is exactly what
     * `MolliePaymentService::safePayment()` exposes as
     * `amount_refunded_cents`; a full refund also transitions the order.
     */
    private function applyRefund(Order $order, int $cumulativeRefundCents): void
    {
        $attempt = MolliePaymentAttempt::query()
            ->where('order_id', (string) $order->id)
            ->sole();

        app(MollieCheckoutService::class)->applyVerifiedPayment($attempt, [
            'id' => $attempt->mollie_payment_id,
            'status' => 'paid',
            'mode' => $attempt->mode,
            'amount_cents' => (int) $attempt->amount_cents,
            'currency' => 'EUR',
            'amount_refunded_cents' => min($cumulativeRefundCents, (int) $order->total_amount),
            'amount_charged_back_cents' => 0,
            'metadata' => [
                'order_id' => (string) $order->id,
                'attempt_id' => (string) $attempt->id,
                'generation' => (string) $attempt->generation,
            ],
        ]);
    }

    public function test_partial_refund_reduces_each_photographers_statement_in_revenue_proportion(): void
    {
        $photographerA = User::factory()->create();
        $photographerB = User::factory()->create();
        $photoA = Photo::factory()->create(['user_id' => $photographerA->id]);
        $photoB = Photo::factory()->create(['user_id' => $photographerB->id]);

        // Fixture arithmetic: two photo lines of 1000 and 2000 cents, a
        // 3000-cent net snapshot, and a 600-cent partial refund.
        $lineA = 1000;
        $lineB = 2000;
        $refundCents = 600;
        // The historical 4 % payment reserve of the 3000-cent net.
        $reserveCents = 120;

        $order = Order::factory()->paid()->create([
            'status' => 'paid',
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
        // A and 400 on B, leaving 800 and 1600 cents of photo revenue. The
        // reserve is prorated over that remainder in the same ratio (96 cents
        // in total, 32 and 64 cents), so each photographer keeps 48 % of the
        // remaining line revenue: 384 and 768 cents.
        $refundOnA = intdiv($refundCents * $lineA, $lineA + $lineB);
        $refundOnB = $refundCents - $refundOnA;
        $remainingA = $lineA - $refundOnA;
        $remainingB = $lineB - $refundOnB;
        $remainingTotal = $remainingA + $remainingB;
        $photoReserveCents = min(
            $remainingTotal,
            intdiv($reserveCents * $remainingTotal, $lineA + $lineB),
        );
        $reserveOnA = intdiv($photoReserveCents * $remainingA, $remainingTotal);
        $reserveOnB = $photoReserveCents - $reserveOnA;
        $expectedA = intdiv($remainingA - $reserveOnA, 2);
        $expectedB = intdiv($remainingB - $reserveOnB, 2);

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
        // 48% of the full photo revenue (4 % reserve on 1000 leaves 960, half
        // of it is 480).
        $this->assertSame(
            480,
            PhotographerStatement::where('user_id', $photographer->id)->sole()->delta_surcharge_earnings_cents,
        );
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
    }

    public function test_refund_above_order_total_is_clamped_and_never_creates_negative_revenue(): void
    {
        [, , $order] = $this->makePaidOrder(3000);

        // A cumulative refund above the recorded order total is clamped to
        // total_amount.
        $this->applyRefund($order, 9000);

        $this->assertSame(3000, $order->fresh()->refunded_amount_cents);

        $this->service->calculatePowerUserDelta(6, 2026);

        // The refund swallows the whole photo revenue: no line goes negative
        // and no statement (in particular none with a negative delta) is
        // booked.
        $this->assertSame(0, PhotographerStatement::query()->count());
    }

    public function test_partial_refund_cannot_be_booked_into_an_already_approved_statement(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $bookedTotal = $statement->total_payable_cents;

        $this->applyRefund($order, 1500);

        $correction = PayoutCorrection::query()->sole();
        $this->assertSame((string) $order->id, (string) $correction->order_id);
        $this->assertSame((string) $photographer->id, (string) $correction->user_id);
        $this->assertSame(-720, $correction->amount_cents);
        $this->assertSame(6, $correction->closed_month);
        $this->assertSame(2026, $correction->closed_year);
        $this->assertNull($correction->settled_month);
        $this->assertNull($correction->settled_year);
        $this->assertNull($correction->settled_at);
        $refundItem = PayoutOpenItem::query()->sole();
        $this->assertSame('refund', $refundItem->source_type);
        $this->assertSame((string) $correction->id, (string) $refundItem->source_id);
        $this->assertSame(-720, $refundItem->amount_cents);
        $this->assertSame(6, $refundItem->origin_month);
        $this->assertSame(2026, $refundItem->origin_year);
        $this->assertNull($refundItem->settled_at);

        $this->assertSame('approved', $statement->fresh()->status);
        $this->assertSame($bookedTotal, $statement->fresh()->total_payable_cents);

        $this->service->finalizeStatements(7, 2026);

        $nextStatement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-720, $nextStatement->earned_amount_cents);
        $this->assertSame(-720, $nextStatement->total_payable_cents);
        $this->assertSame(7, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);
        $this->assertSame(7, $refundItem->fresh()->settled_month);
        $this->assertSame(2026, $refundItem->fresh()->settled_year);
        $this->assertNotNull($refundItem->fresh()->settled_at);

        $resource = (new PhotographerStatementResource($nextStatement))->resolve(request());
        $this->assertSame(1, count($resource['corrections']));
        $this->assertSame(-720, $resource['corrections'][0]['amount_cents']);
        $this->assertSame(6, $resource['corrections'][0]['closed_month']);
        $this->assertSame(7, $resource['corrections'][0]['settled_month']);
        $this->assertSame(2026, $resource['corrections'][0]['settled_year']);
        $this->assertArrayNotHasKey('month', $resource['corrections'][0]);
        $this->assertSame((string) $order->id, $resource['corrections'][0]['order_id']);
    }

    public function test_late_full_refund_reverses_the_share_booked_in_the_closed_month(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $bookedShare = $statement->delta_surcharge_earnings_cents;

        $this->applyRefund($order, 5000);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(-$bookedShare, PayoutCorrection::query()->sole()->amount_cents);
        $this->assertSame(-$bookedShare, PayoutOpenItem::query()->sole()->amount_cents);

        $this->service->finalizeStatements(7, 2026);

        $nextStatement = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-$bookedShare, $nextStatement->total_payable_cents);
    }

    public function test_late_full_refund_uses_the_booked_share_after_the_photo_is_deleted(): void
    {
        [$photographer, $photo, $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $bookedShare = $statement->delta_surcharge_earnings_cents;

        $photo->delete();

        $this->applyRefund($order, 5000);

        $this->assertDatabaseHas('payout_corrections', [
            'order_id' => $order->id,
            'user_id' => $photographer->id,
            'amount_cents' => -$bookedShare,
        ]);
    }

    public function test_late_partial_refund_uses_the_booking_basis_and_caps_at_the_booked_share(): void
    {
        [$photographer, $photo, $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $bookedShare = $statement->delta_surcharge_earnings_cents;
        $photo->delete();

        $this->applyRefund($order, 1500);

        $firstCorrection = PayoutCorrection::query()->sole();
        $this->assertSame(-720, $firstCorrection->amount_cents);

        // The provider reports a cumulative refund above the charge; it is
        // clamped to the charged amount, so this is a full reversal: the
        // corrections cap at the booked share and the order turns `refunded`.
        $this->applyRefund($order, 9000);

        $this->assertSame(2, PayoutCorrection::query()->count());
        $this->assertSame(-$bookedShare, (int) PayoutCorrection::query()->sum('amount_cents'));
        $this->assertSame(-$bookedShare, (int) PayoutOpenItem::query()->where('source_type', 'refund')->sum('amount_cents'));
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_late_full_refund_after_a_partial_one_reverses_only_the_remaining_booked_share(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $bookedShare = $statement->delta_surcharge_earnings_cents;

        $this->applyRefund($order, 1500);
        $this->applyRefund($order, 5000);

        $corrections = PayoutCorrection::query()->orderBy('refund_amount_cents')->get();
        $this->assertCount(2, $corrections);
        $this->assertSame(-720, $corrections[0]->amount_cents);
        $this->assertSame(-($bookedShare - 720), $corrections[1]->amount_cents);
        $this->assertSame(-$bookedShare, (int) $corrections->sum('amount_cents'));
    }

    public function test_month_recalculation_upserts_bookings_idempotently_and_replaces_changed_values(): void
    {
        [$photographer, , $order] = $this->makePaidOrder(4000);

        $this->service->calculatePowerUserDelta(6, 2026);
        $booking = PayoutOrderBooking::query()->sole();
        $original = $booking->only([
            'id',
            'order_id',
            'user_id',
            'statement_id',
            'month',
            'year',
            'share_cents',
            'photo_revenue_cents',
            'refunded_cents',
        ]);
        $this->assertSame((string) $order->id, (string) $booking->order_id);
        $this->assertSame((string) $photographer->id, (string) $booking->user_id);
        // 4000 cents minus the 4 % reserve (160) leaves 3840, half is 1920.
        $this->assertSame(1920, $booking->share_cents);
        $this->assertSame(4000, $booking->photo_revenue_cents);
        $this->assertSame(0, $booking->refunded_cents);
        $this->assertNotNull($booking->statement_id);

        $this->service->calculatePowerUserDelta(6, 2026);
        $this->assertDatabaseCount('payout_order_bookings', 1);
        $this->assertSame($original, PayoutOrderBooking::query()->sole()->only(array_keys($original)));

        $snapshot = $order->invoiceSnapshot;
        $details = $snapshot->customer_details;
        $details['items'][0]['price'] = 3000;
        $details[InvoiceSnapshot::PAYOUT_PHOTO_REVENUE_KEY] = ['0' => 3000];
        $snapshot->customer_details = $details;
        $snapshot->total_net = 3000;
        $snapshot->total_gross = 3000;
        $snapshot->save();

        $this->service->calculatePowerUserDelta(6, 2026);

        $updatedBooking = PayoutOrderBooking::query()->sole();
        $this->assertSame((string) $booking->id, (string) $updatedBooking->id);
        // 3000 cents minus the 4 % reserve (120) leaves 2880, half is 1440.
        $this->assertSame(1440, $updatedBooking->share_cents);
        $this->assertSame(3000, $updatedBooking->photo_revenue_cents);
        $this->assertSame(1, PayoutOrderBooking::query()->count());
    }

    public function test_locked_statement_booking_is_not_changed_by_month_recalculation(): void
    {
        [$photographer, $photo, $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $booking = PayoutOrderBooking::query()->sole();
        $originalBooking = $booking->only([
            'id',
            'statement_id',
            'share_cents',
            'photo_revenue_cents',
            'refunded_cents',
        ]);
        $originalStatement = $statement->only([
            'status',
            'delta_surcharge_earnings_cents',
            'earned_amount_cents',
            'total_payable_cents',
        ]);

        $photo->delete();
        $this->service->calculatePowerUserDelta(6, 2026);

        $this->assertSame($originalBooking, $booking->fresh()->only(array_keys($originalBooking)));
        $this->assertSame($originalStatement, $statement->fresh()->only(array_keys($originalStatement)));
        $this->assertSame((string) $order->id, (string) $booking->order_id);
    }

    public function test_pre_v052_order_without_a_booking_uses_current_photo_recomputation(): void
    {
        [$photographer, $photo, $order] = $this->makePaidOrder();
        $this->approveJuneStatement($photographer);

        // Removing the row models a statement booked before V052. Its stored
        // share is not inferred: the legacy fallback sees the current photo set.
        PayoutOrderBooking::query()->where('order_id', $order->id)->delete();
        $photo->delete();

        $this->applyRefund($order, 5000);

        $this->assertSame(0, PayoutCorrection::query()->count());
        $this->assertSame(0, PayoutOpenItem::query()->where('source_type', 'refund')->count());
    }

    public function test_negative_correction_carries_across_a_missing_statement_month(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $this->approveJuneStatement($photographer);

        $this->applyRefund($order, 1500);
        $this->service->finalizeStatements(7, 2026);
        $julyCarry = PayoutOpenItem::query()
            ->where('source_type', 'carry')
            ->where('origin_month', 7)
            ->where('origin_year', 2026)
            ->sole();
        $this->assertSame(-720, $julyCarry->amount_cents);
        $this->assertNull($julyCarry->settled_at);

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
        $this->assertSame(-720, $september->rolled_over_amount_cents);
        $this->assertSame(-720, $september->total_payable_cents);
        $this->assertSame(9, $julyCarry->fresh()->settled_month);
        $this->assertSame(-720, PayoutOpenItem::query()
            ->where('source_type', 'carry')
            ->where('origin_month', 9)
            ->where('origin_year', 2026)
            ->sole()
            ->amount_cents);
    }

    public function test_zero_balance_after_negative_correction_stops_the_older_rollover(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $this->approveJuneStatement($photographer);

        $this->applyRefund($order, 1500);
        $this->service->finalizeStatements(7, 2026);

        PhotographerStatement::query()->create([
            'user_id' => $photographer->id,
            'month' => 8,
            'year' => 2026,
            'pool_earnings_cents' => 720,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-720, $august->rolled_over_amount_cents);
        $this->assertSame(0, $august->total_payable_cents);
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $photographer->id,
            'source_type' => 'carry',
            'origin_month' => 8,
            'origin_year' => 2026,
        ]);

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
        $this->assertDatabaseMissing('payout_open_items', [
            'user_id' => $photographer->id,
            'source_type' => 'carry',
            'origin_month' => 9,
            'origin_year' => 2026,
        ]);
    }

    public function test_zero_balance_after_positive_rollover_stops_the_older_rollover(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
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
            'pool_earnings_cents' => 720,
            'delta_surcharge_earnings_cents' => 0,
        ]);
        $this->service->finalizeStatements(7, 2026);

        PayoutCorrection::query()->create([
            'order_id' => $order->id,
            'user_id' => $photographer->id,
            'refund_amount_cents' => 1500,
            'amount_cents' => -720,
            'closed_month' => 6,
            'closed_year' => 2026,
        ]);
        PayoutOpenItem::query()->create([
            'user_id' => $photographer->id,
            'source_type' => 'refund',
            'source_id' => PayoutCorrection::query()->sole()->id,
            'order_id' => $order->id,
            'origin_month' => 6,
            'origin_year' => 2026,
            'amount_cents' => -720,
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
        $this->assertSame(720, $august->rolled_over_amount_cents);
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

    public function test_replayed_refund_and_recalculation_book_a_correction_once(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $this->approveJuneStatement($photographer);

        $this->applyRefund($order, 1500);
        $this->applyRefund($order, 1500);
        $this->assertSame(1, PayoutCorrection::query()->count());
        $this->assertSame(1, PayoutOpenItem::query()->where('source_type', 'refund')->count());

        $this->service->finalizeStatements(7, 2026);
        $this->service->finalizeStatements(7, 2026);
        $this->assertSame(-720, PhotographerStatement::query()
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

        $this->assertSame(-720, PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 7)
            ->where('year', 2026)
            ->sole()
            ->total_payable_cents);
        $this->assertNotNull(PayoutCorrection::query()->sole()->settled_at);
        $this->assertNotNull(PayoutOpenItem::query()
            ->where('source_type', 'refund')
            ->sole()
            ->settled_at);
    }

    public function test_late_correction_never_mutates_an_approved_statement(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $statement = $this->approveJuneStatement($photographer);
        $original = $statement->only([
            'status',
            'pool_earnings_cents',
            'delta_surcharge_earnings_cents',
            'earned_amount_cents',
            'rolled_over_amount_cents',
            'total_payable_cents',
        ]);

        $this->applyRefund($order, 1500);
        $this->service->calculatePowerUserDelta(6, 2026);
        $this->service->finalizeStatements(6, 2026);

        $this->assertSame($original, $statement->fresh()->only(array_keys($original)));
        $this->assertSame(1, PayoutCorrection::query()->count());
        $this->assertSame(1, PayoutOpenItem::query()->where('source_type', 'refund')->count());
    }

    public function test_correction_created_after_next_month_finalized_is_claimed_by_following_finalize(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
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

        $this->applyRefund($order, 1500);

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
        $this->assertSame(-720, $august->earned_amount_cents);
        $this->assertSame(-720, $august->total_payable_cents);
        $this->assertSame(8, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);

        $this->service->finalizeStatements(8, 2026);
        $this->assertSame(-720, $august->fresh()->earned_amount_cents);
        $this->assertSame(-720, $august->fresh()->total_payable_cents);
        $this->assertSame(1, PayoutCorrection::query()->count());
    }

    public function test_correction_skips_approved_statement_and_is_claimed_in_the_following_period(): void
    {
        [$photographer, , $order] = $this->makePaidOrder();
        $this->approveJuneStatement($photographer);
        $this->applyRefund($order, 1500);

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
        $this->assertNull(PayoutOpenItem::query()
            ->where('source_type', 'refund')
            ->sole()
            ->settled_at);
        $this->assertNull($correction->fresh()->settled_month);
        $this->assertNull($correction->fresh()->settled_year);

        $this->service->finalizeStatements(8, 2026);

        $august = PhotographerStatement::query()
            ->where('user_id', $photographer->id)
            ->where('month', 8)
            ->where('year', 2026)
            ->sole();
        $this->assertSame(-720, $august->earned_amount_cents);
        $this->assertSame(-720, $august->total_payable_cents);
        $this->assertSame(8, $correction->fresh()->settled_month);
        $this->assertSame(2026, $correction->fresh()->settled_year);
        $this->assertNotNull($correction->fresh()->settled_at);
        $this->assertSame(8, PayoutOpenItem::query()
            ->where('source_type', 'refund')
            ->sole()
            ->settled_month);
    }
}
