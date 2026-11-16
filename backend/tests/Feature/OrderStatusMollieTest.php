<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The admin status route is an access grant: `paid` is download-eligible and
 * `cancelled` must not be written while money could still arrive. Both guards
 * therefore trust only an authenticated Mollie answer.
 */
class OrderStatusMollieTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
    }

    public function test_a_locally_paid_attempt_allows_the_paid_transition_without_a_provider_call(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_PAID);
        $client = $this->fakeMollie([], retainRequests: true);

        $this->changeStatus($order, 'paid')->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_a_provider_confirmed_payment_with_the_exact_amount_allows_paid(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeFetch($this->paymentFor($attempt, 'paid'));

        $this->changeStatus($order, 'paid')->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_a_provider_payment_with_a_different_amount_is_rejected(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeFetch($this->paymentFor($attempt, 'paid', [
            'amount' => ['value' => '49.99', 'currency' => 'EUR'],
        ]));

        $response = $this->changeStatus($order, 'paid')->assertStatus(422);
        $this->assertStringContainsString('bestätigten Zahlung', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPaidProviderStatusProvider(): array
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

    #[DataProvider('nonPaidProviderStatusProvider')]
    public function test_a_non_paid_provider_status_never_allows_paid(string $status): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeFetch($this->paymentFor($attempt, $status));

        $this->changeStatus($order, 'paid')->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_an_order_without_a_mollie_attempt_is_rejected(): void
    {
        $order = $this->orderWithAttempt(null);

        $this->changeStatus($order, 'paid')->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_an_attempt_without_a_provider_payment_id_is_rejected(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_CREATING, paymentId: null);
        $client = $this->fakeMollie([], retainRequests: true);

        $this->changeStatus($order, 'paid')->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_a_lookup_failure_returns_502_and_logs_without_secrets(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeFetch(MockResponse::error(500, 'Server Error', 'boom'));
        Log::spy();

        $response = $this->changeStatus($order, 'paid')->assertStatus(502);
        $this->assertStringContainsString('bei Mollie nicht geprüft', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($order, $attempt): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'order.paid.mollie_lookup_failed'
                    && $context['order_id'] === (string) $order->getKey()
                    && $context['attempt_id'] === (string) $attempt->getKey()
                    && $context['reason'] === 'provider_unavailable'
                    && ! str_contains($encoded, 'test_'.str_repeat('a', 30));
            })
            ->once();
    }

    public function test_an_open_cancelable_payment_is_canceled_before_the_order_changes(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeMollie([
            GetPaymentRequest::class => $this->paymentFor($attempt, 'open'),
            CancelPaymentRequest::class => $this->paymentFor($attempt, 'canceled'),
        ], retainRequests: true);

        $this->changeStatus($order, 'cancelled')->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertSame(MolliePaymentAttempt::STATUS_CANCELED, $attempt->fresh()->status);
    }

    public function test_an_already_canceled_payment_is_not_canceled_twice(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_CANCELED);
        $attempt = $order->molliePaymentAttempts()->sole();
        $client = $this->fakeMollie([
            GetPaymentRequest::class => $this->paymentFor($attempt, 'canceled'),
        ], retainRequests: true);

        $this->changeStatus($order, 'cancelled')->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $cancels = array_filter(
            $client->getHttpClient()->recorded(),
            fn (array $entry): bool => $entry[0]->getRequest() instanceof CancelPaymentRequest,
        );
        $this->assertSame([], $cancels);
    }

    public function test_a_paid_attempt_blocks_cancellation(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_PAID);
        $client = $this->fakeMollie([], retainRequests: true);

        $response = $this->changeStatus($order, 'cancelled')->assertStatus(502);
        $this->assertStringContainsString('bereits bezahlt', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unresolvedAttemptStatusProvider(): array
    {
        return [
            'creating' => [MolliePaymentAttempt::STATUS_CREATING],
            'ambiguous' => [MolliePaymentAttempt::STATUS_AMBIGUOUS],
        ];
    }

    #[DataProvider('unresolvedAttemptStatusProvider')]
    public function test_an_unidentified_attempt_blocks_cancellation(string $status): void
    {
        $order = $this->orderWithAttempt($status, paymentId: null);
        $client = $this->fakeMollie([], retainRequests: true);

        $response = $this->changeStatus($order, 'cancelled')->assertStatus(502);
        $this->assertStringContainsString('ungeklärter Mollie-Zahlungsversuch', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    public function test_a_non_cancelable_payment_blocks_cancellation(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeMollie([
            GetPaymentRequest::class => $this->paymentFor($attempt, 'open', ['isCancelable' => false]),
        ], retainRequests: true);

        $response = $this->changeStatus($order, 'cancelled')->assertStatus(502);
        $this->assertStringContainsString('nicht storniert werden', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_a_cancel_answer_that_is_not_canceled_returns_502_and_logs(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $attempt = $order->molliePaymentAttempts()->sole();
        $this->fakeMollie([
            GetPaymentRequest::class => $this->paymentFor($attempt, 'open'),
            CancelPaymentRequest::class => $this->paymentFor($attempt, 'pending'),
        ], retainRequests: true);
        Log::spy();

        $response = $this->changeStatus($order, 'cancelled')->assertStatus(502);
        $this->assertStringContainsString('bei Mollie nicht abgeschlossen', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'order.cancel.mollie_payment_not_cancelled'
                && $context['order_id'] === (string) $order->getKey()
                && $context['payment_id'] === $attempt->mollie_payment_id
                && $context['payment_status'] === 'pending')
            ->once();
    }

    public function test_a_cancel_lookup_failure_returns_502_and_keeps_the_order(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_OPEN);
        $this->fakeFetch(MockResponse::error(500, 'Server Error', 'boom'));
        Log::spy();

        $response = $this->changeStatus($order, 'cancelled')->assertStatus(502);
        $this->assertStringContainsString('bei Mollie nicht abgeschlossen', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'order.cancel.mollie_payment_not_cancelled'
                && $context['reason'] === 'provider_unavailable')
            ->once();
    }

    public function test_a_terminal_attempt_without_a_provider_id_does_not_block_cancellation(): void
    {
        $order = $this->orderWithAttempt(MolliePaymentAttempt::STATUS_FAILED, paymentId: null);
        $client = $this->fakeMollie([], retainRequests: true);

        $this->changeStatus($order, 'cancelled')->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertSame([], $client->getHttpClient()->recorded());
    }

    private function changeStatus(Order $order, string $status): TestResponse
    {
        return $this->putJson(
            "/api/management/orders/{$order->id}/status",
            ['status' => $status],
            $this->adminHeaders(),
        );
    }

    private function orderWithAttempt(?string $attemptStatus, ?string $paymentId = 'tr_admin12345678'): Order
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'total_amount' => 5000,
            'stripe_payment_intent_id' => null,
        ]);

        if ($attemptStatus === null) {
            return $order;
        }

        MolliePaymentAttempt::create([
            'order_id' => (string) $order->id,
            'generation' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'mollie_payment_id' => $paymentId,
            'mode' => 'test',
            'credential_fingerprint' => hash('sha256', 'mollie-key|test_'.str_repeat('a', 30)),
            'amount_cents' => 5000,
            'currency' => 'EUR',
            'status' => $attemptStatus,
            'checkout_url' => $paymentId === null ? null : 'https://www.mollie.com/checkout/select-method/'.$paymentId,
            'snapshot' => ['gross_cents' => 5000, 'currency' => 'EUR'],
            'paid_at' => $attemptStatus === MolliePaymentAttempt::STATUS_PAID ? now() : null,
        ]);

        return $order->refresh();
    }

    private function fakeFetch(MockResponse $response): void
    {
        $this->fakeMollie([GetPaymentRequest::class => $response], retainRequests: true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentFor(MolliePaymentAttempt $attempt, string $status, array $overrides = []): MockResponse
    {
        $amount = $overrides['amount'] ?? null;
        unset($overrides['amount']);

        return $this->molliePaymentResponse(
            id: $attempt->mollie_payment_id ?? 'tr_admin12345678',
            status: $status,
            amount: is_array($amount)
                ? new Money((string) $amount['currency'], (string) $amount['value'])
                : $this->mollieAmount($attempt->amount_cents),
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

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ['Authorization' => 'Bearer '.auth('api')->login($admin)];
    }
}
