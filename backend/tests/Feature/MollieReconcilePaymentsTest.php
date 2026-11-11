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

    private function fakeFetch(MockResponse $response): void
    {
        $this->fakeMollie([GetPaymentRequest::class => $response], retainRequests: true);
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
                'items' => [['photoId' => 'photo-1', 'tier' => 'web', 'price' => 5000]],
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
