<?php

namespace Tests\Feature;

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
 * The German return view polls this owner-scoped endpoint. Only a verified
 * provider fetch may turn the order paid; the query string is presentation.
 */
class MolliePaymentStatusTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
        Mail::fake();
    }

    public function test_an_open_payment_reports_the_hosted_link_and_the_pending_status(): void
    {
        [$user, $order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'open'));

        $this->actingAsUser($user)
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJson([
                'order_id' => (string) $order->id,
                'status' => 'pending_payment',
                'payment_status' => 'open',
                'payment_ambiguous' => false,
                'retryable' => false,
                'checkout_url' => $attempt->checkout_url,
            ]);
    }

    public function test_a_verified_paid_status_settles_the_order_and_hides_the_link(): void
    {
        [$user, $order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->actingAsUser($user)
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJson([
                'status' => 'paid',
                'payment_status' => 'paid',
                'checkout_url' => null,
            ]);

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_failed_payment_is_reported_as_retryable(): void
    {
        [$user, $order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'failed'));

        $this->actingAsUser($user)
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJson([
                'status' => 'pending_payment',
                'payment_status' => 'failed',
                'retryable' => true,
            ]);
    }

    public function test_a_non_owner_cannot_read_the_payment_status(): void
    {
        [, $order, $attempt] = $this->pendingOrderWithAttempt();
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->actingAsUser(User::factory()->create())
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertNotFound();

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_an_order_without_a_mollie_attempt_reports_no_payment_state(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'total_amount' => 5000,
        ]);

        $this->actingAsUser($user)
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJson([
                'status' => 'pending_payment',
                'payment_status' => null,
                'checkout_url' => null,
            ]);
    }

    public function test_an_unconfigured_provider_reports_the_last_known_state_without_granting_access(): void
    {
        [$user, $order, $attempt] = $this->pendingOrderWithAttempt();
        $this->disablesMollie();
        $client = $this->fakeMollie([], retainRequests: true);

        $this->actingAsUser($user)
            ->getJson("/api/orders/{$order->id}/payment-status")
            ->assertOk()
            ->assertJson([
                'status' => 'pending_payment',
                'payment_status' => 'open',
                'checkout_url' => $attempt->checkout_url,
            ]);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    private function fakeFetch(MockResponse $response): void
    {
        $this->fakeMollie([GetPaymentRequest::class => $response], retainRequests: true);
    }

    /**
     * @return array{0: User, 1: Order, 2: MolliePaymentAttempt}
     */
    private function pendingOrderWithAttempt(): array
    {
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
            'mollie_payment_id' => 'tr_status1234567',
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'status' => MolliePaymentAttempt::STATUS_OPEN,
            'checkout_url' => 'https://www.mollie.com/checkout/select-method/tr_status1234567',
            'snapshot' => ['gross_cents' => 5000, 'currency' => 'EUR'],
        ]);

        return [$user, $order, $attempt];
    }

    private function actingAsUser(User $user): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentFor(MolliePaymentAttempt $attempt, string $status, array $overrides = []): MockResponse
    {
        return $this->molliePaymentResponse(
            id: $attempt->mollie_payment_id,
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
