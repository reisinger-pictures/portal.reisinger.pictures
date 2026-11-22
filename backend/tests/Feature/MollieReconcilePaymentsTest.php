<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The scheduled reconciliation is convergence, not a second source of truth:
 * every settled attempt was re-read with the authenticated provider
 * credential, and an unresolved create is only ever marked ambiguous.
 */
class MollieReconcilePaymentsTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
        Mail::fake();
    }

    public function test_a_verified_paid_attempt_settles_its_pending_order(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt('open');
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(MolliePaymentAttempt::STATUS_PAID, $attempt->fresh()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_an_open_payment_is_observed_without_granting_access(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt('open');
        $this->fakeFetch($this->paymentFor($attempt, 'open'));

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(MolliePaymentAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->last_reconciled_at);
        Mail::assertNothingQueued();
    }

    public function test_a_paid_attempt_with_a_pending_order_is_recovered(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt('paid');

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    public function test_an_unresolved_create_becomes_ambiguous_only_after_the_idempotency_window(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt(null, MolliePaymentAttempt::STATUS_CREATING);
        $client = $this->fakeMollie([], retainRequests: true);

        // Inside the window the attempt may still be bound by a webhook.
        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);
        $this->assertSame(MolliePaymentAttempt::STATUS_CREATING, $attempt->fresh()->status);
        $this->assertSame([], $client->getHttpClient()->recorded());

        // Once the provider's idempotency window has passed, an unanswered
        // create is ambiguous and must never be replaced.
        $attempt->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame(MolliePaymentAttempt::STATUS_AMBIGUOUS, $attempt->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_a_provider_lookup_failure_leaves_the_attempt_untouched(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt('open');
        $this->fakeFetch(MockResponse::error(500, 'Server Error', 'boom'));

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(MolliePaymentAttempt::STATUS_OPEN, $attempt->fresh()->status);
    }

    public function test_an_unconfigured_provider_is_a_no_op(): void
    {
        [$order, $attempt] = $this->pendingOrderWithAttempt('open');
        $this->disablesMollie();
        $client = $this->fakeMollie([], retainRequests: true);

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_a_late_refund_is_recorded_without_a_webhook_or_an_order_visit(): void
    {
        // Issue #3: a verified paid attempt is otherwise never re-read, so a
        // refund that arrives without a webhook stays invisible and overpays
        // the photographer. The reconcile run must read the provider fact and
        // record it exactly once through applyVerifiedPayment().
        [$order, $attempt] = $this->paidOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'amountRefunded' => ['value' => '50.00', 'currency' => 'EUR'],
        ]));

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0)
            ->expectsOutputToContain('1 late refunds recorded');

        $fresh = $order->fresh();
        $this->assertSame(5000, $fresh->refunded_amount_cents);
        $this->assertSame('refunded', $fresh->status);

        // A second run reads nothing: the verified read advanced the
        // reconciliation timestamp, so no duplicate correction is booked.
        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0)
            ->expectsOutputToContain('0 late refunds recorded');
        $this->assertSame(5000, $order->fresh()->refunded_amount_cents);
    }

    public function test_a_recently_reconciled_paid_attempt_is_not_reread(): void
    {
        // Issue #3: the interval throttle must hold — an attempt observed
        // moments ago is not fetched again by the five-minute run.
        [$order, $attempt] = $this->paidOrderWithAttempt();
        $attempt->last_reconciled_at = now();
        $attempt->save();
        $client = $this->fakeMollie([], retainRequests: true);

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->assertExitCode(0);

        $this->assertSame([], $client->getHttpClient()->recorded());
        $this->assertSame(0, (int) $order->fresh()->refunded_amount_cents);
    }

    public function test_a_broken_method_list_does_not_block_settling_a_collected_payment(): void
    {
        // The creation gate needs a method list and a webhook; settling money
        // that was already collected needs the credential alone. A forbidden
        // `klarna` entry must therefore never strand a paid order — this
        // command is the last rescue when a webhook was lost.
        [$order, $attempt] = $this->pendingOrderWithAttempt('paid');
        $this->configureMollie(['paybybank', 'klarna', 'creditcard']);

        $this->artisan('mollie:reconcile-payments', ['--minutes' => 0])
            ->doesntExpectOutputToContain('nothing to reconcile')
            ->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertQueued(InvoiceMail::class, 1);
    }

    private function fakeFetch(MockResponse $response): void
    {
        $this->fakeMollie([GetPaymentRequest::class => $response], retainRequests: true);
    }

    /**
     * An already-paid order with a verified paid attempt — the state the late
     * refund reconciliation (#3) starts from.
     *
     * @return array{0: Order, 1: MolliePaymentAttempt}
     */
    private function paidOrderWithAttempt(): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 5000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => 'Tester',
                'email' => 'buyer@example.test',
                // The snapshot feeds Photo::whereIn('id', ...); on PostgreSQL
                // the id column is a real uuid, so a synthetic id would abort
                // the verified-payment path with a 22P02 cast error.
                'items' => [['photoId' => (string) Str::uuid(), 'tier' => 'web', 'price' => 5000]],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);
        $attempt = MolliePaymentAttempt::create([
            'order_id' => (string) $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => 'tr_reconcile12345',
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'status' => MolliePaymentAttempt::STATUS_PAID,
            'checkout_url' => 'https://www.mollie.com/checkout/select-method/tr_reconcile12345',
            'snapshot' => ['gross_cents' => 5000, 'currency' => 'EUR'],
            'paid_at' => now()->subDays(5),
            'last_reconciled_at' => now()->subDays(5),
        ]);

        return [$order, $attempt];
    }

    /**
     * @return array{0: Order, 1: MolliePaymentAttempt}
     */
    private function pendingOrderWithAttempt(?string $attemptStatus, ?string $statusOverride = 'open'): array
    {
        $status = $attemptStatus ?? $statusOverride;
        $paymentId = $status === MolliePaymentAttempt::STATUS_CREATING ? null : 'tr_reconcile12345';

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'name' => 'Tester',
                'email' => 'buyer@example.test',
                // The snapshot feeds Photo::whereIn('id', ...); on PostgreSQL
                // the id column is a real uuid, so a synthetic id would abort
                // the verified-payment path with a 22P02 cast error.
                'items' => [['photoId' => (string) Str::uuid(), 'tier' => 'web', 'price' => 5000]],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);
        $attempt = MolliePaymentAttempt::create([
            'order_id' => (string) $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => $paymentId,
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'status' => $status,
            'checkout_url' => $paymentId === null ? null : 'https://www.mollie.com/checkout/select-method/'.$paymentId,
            'snapshot' => ['gross_cents' => 5000, 'currency' => 'EUR'],
        ]);

        return [$order, $attempt];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentFor(MolliePaymentAttempt $attempt, string $status, array $overrides = []): MockResponse
    {
        return $this->molliePaymentResponse(
            id: $attempt->mollie_payment_id ?? 'tr_reconcile12345',
            status: $status,
            amount: $this->mollieAmount($attempt->amount_cents),
            metadata: [
                'order_id' => (string) $attempt->order_id,
                'attempt_id' => (string) $attempt->id,
                'generation' => (string) $attempt->generation,
                'amount_cents' => (string) $attempt->amount_cents,
                'currency' => 'EUR',
            ],
            mode: $attempt->mode,
            overrides: $overrides,
        );
    }
}
