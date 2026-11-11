<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\User;
use App\Services\MolliePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * Mollie's classic webhook carries only a payment id. The endpoint must fetch
 * the payment with the authenticated credential and then verify id, attempt
 * metadata, mode, amount, currency, and order before it may grant access.
 */
class MollieWebhookTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
        Mail::fake();
    }

    public function test_a_verified_paid_payment_marks_the_order_paid_and_queues_the_invoice(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(MolliePaymentAttempt::STATUS_PAID, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->paid_at);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_a_duplicate_paid_delivery_is_idempotent(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();
        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPaidStatusProvider(): array
    {
        return [
            'open' => ['open'],
            'pending' => ['pending'],
            'authorized' => ['authorized'],
            'failed' => ['failed'],
            'canceled' => ['canceled'],
            'expired' => ['expired'],
        ];
    }

    #[DataProvider('nonPaidStatusProvider')]
    public function test_a_non_paid_status_never_grants_access(string $status): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, $status));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame($status, $attempt->fresh()->status);
        Mail::assertNothingQueued();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mismatchProvider(): array
    {
        return [
            'amount' => [['amount' => '49.99']],
            'currency' => [['currency' => 'USD']],
            'mode' => [['mode' => 'live']],
            'payment id' => [['id' => 'tr_otherpayment12']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('mismatchProvider')]
    public function test_a_verified_identity_mismatch_is_ignored(array $overrides): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', $overrides));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertNotSame(MolliePaymentAttempt::STATUS_PAID, $attempt->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_a_mismatched_attempt_metadata_is_ignored(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [], [
            'attempt_id' => (string) Str::uuid(),
        ]));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_a_lost_create_answer_is_bound_by_verified_metadata_and_settled(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt(null);
        $this->assertNull($attempt->mollie_payment_id);

        $paymentId = 'tr_lostanswer1234';
        $this->fakeFetch($this->paymentFor($attempt, 'paid', ['id' => $paymentId]));

        $this->postJson('/api/webhooks/mollie', ['id' => $paymentId])->assertOk();

        $this->assertSame($paymentId, $attempt->fresh()->mollie_payment_id);
        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_a_lost_create_answer_with_a_mismatched_amount_is_not_bound(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt(null);
        $paymentId = 'tr_lostmismatch123';

        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'id' => $paymentId,
            'amount' => '49.99',
        ]));

        $this->postJson('/api/webhooks/mollie', ['id' => $paymentId])->assertOk();

        $this->assertNull($attempt->fresh()->mollie_payment_id);
        $this->assertSame('pending_payment', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_an_unknown_payment_is_acknowledged_without_a_lookup_side_effect(): void
    {
        $this->fakeFetch(MockResponse::payment(
            id: 'tr_unknown123456',
            status: 'paid',
            amount: new Money('EUR', '10.00'),
            overrides: ['mode' => 'test', 'metadata' => ['attempt_id' => (string) Str::uuid()]],
        ));

        $this->postJson('/api/webhooks/mollie', ['id' => 'tr_unknown123456'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_invalid_payment_id_is_acknowledged_without_a_provider_call(): void
    {
        $client = $this->fakeMollie([], retainRequests: true);

        $this->postJson('/api/webhooks/mollie', ['id' => 'not-a-payment'])
            ->assertOk();

        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_an_unconfigured_provider_returns_a_retryable_503(): void
    {
        $this->disablesMollie();
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $client = $this->fakeMollie([], retainRequests: true);

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])
            ->assertStatus(503);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_a_lookup_failure_is_retryable_for_a_server_error_but_not_for_an_unknown_payment(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();

        $calls = 0;
        $this->fakeMollie([
            GetPaymentRequest::class => function () use (&$calls): MockResponse {
                $calls++;

                return $calls === 1
                    ? MockResponse::error(500, 'Server Error', 'boom')
                    : MockResponse::notFound();
            },
        ], retainRequests: true);

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])
            ->assertStatus(503);
        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])
            ->assertOk();

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_a_full_refund_revokes_access(): void
    {
        [$order, $attempt] = $this->paidOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'amountRefunded' => ['value' => '50.00', 'currency' => 'EUR'],
        ]));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_a_partial_refund_keeps_access(): void
    {
        [$order, $attempt] = $this->paidOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'amountRefunded' => ['value' => '10.00', 'currency' => 'EUR'],
        ]));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_chargeback_marks_the_order_disputed(): void
    {
        [$order, $attempt] = $this->paidOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'amountChargedBack' => ['value' => '50.00', 'currency' => 'EUR'],
        ]));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
    }

    public function test_a_paid_notification_cannot_reopen_a_cancelled_order(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt(orderStatus: 'cancelled');
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->postJson('/api/webhooks/mollie', ['id' => $attempt->mollie_payment_id])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    private function fakeFetch(MockResponse $response): void
    {
        $this->fakeMollie([GetPaymentRequest::class => $response], retainRequests: true);
    }

    /**
     * @return array{0: Order, 1: MolliePaymentAttempt}
     */
    private function pendingOrderWithAttempt(?string $paymentId = 'tr_pending1234567', string $orderStatus = 'pending_payment'): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => $orderStatus,
            'total_amount' => 5000,
        ]);
        $this->createSnapshot($order);
        $attempt = $this->createAttempt($order, $paymentId);

        return [$order, $attempt];
    }

    /**
     * @return array{0: Order, 1: MolliePaymentAttempt}
     */
    private function paidOrderWithAttempt(): array
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt();
        $order->update(['status' => 'paid']);
        $attempt->update(['status' => MolliePaymentAttempt::STATUS_PAID, 'paid_at' => now()]);

        return [$order, $attempt->fresh()];
    }

    private function createSnapshot(Order $order): void
    {
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-'.str_pad((string) (InvoiceSnapshot::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'customer_details' => [
                'name' => 'Tester',
                'email' => 'buyer@example.test',
                'items' => [['photoId' => 'photo-1', 'tier' => 'web', 'price' => 5000]],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);
    }

    private function createAttempt(Order $order, ?string $paymentId): MolliePaymentAttempt
    {
        return MolliePaymentAttempt::create([
            'order_id' => (string) $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => $paymentId,
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'status' => $paymentId === null ? MolliePaymentAttempt::STATUS_CREATING : MolliePaymentAttempt::STATUS_OPEN,
            'snapshot' => ['gross_cents' => 5000, 'currency' => 'EUR'],
        ]);
    }

    /**
     * @param  array<string, string>  $metadataOverrides
     * @param  array<string, mixed>  $overrides
     */
    private function paymentFor(
        MolliePaymentAttempt $attempt,
        string $status,
        array $overrides = [],
        array $metadataOverrides = [],
    ): MockResponse {
        $amount = $overrides['amount'] ?? null;
        $currency = $overrides['currency'] ?? 'EUR';
        unset($overrides['amount'], $overrides['currency']);

        $paymentId = $overrides['id'] ?? $attempt->mollie_payment_id ?? 'tr_generated12345';
        unset($overrides['id']);

        return $this->molliePaymentResponse(
            id: $paymentId,
            status: $status,
            amount: new Money($currency, $amount ?? MolliePaymentService::decimalFromCents($attempt->amount_cents)),
            metadata: array_merge([
                'order_id' => (string) $attempt->order_id,
                'attempt_id' => (string) $attempt->id,
                'generation' => (string) $attempt->generation,
                'amount_cents' => (string) $attempt->amount_cents,
                'currency' => 'EUR',
            ], $metadataOverrides),
            mode: $overrides['mode'] ?? $attempt->mode,
            overrides: array_diff_key($overrides, ['mode' => true]),
        );
    }
}
