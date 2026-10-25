<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StripePaymentService;
use App\Support\ActorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * `paid` is download-eligible (PurchaseService::DOWNLOAD_ELIGIBLE_STATUSES), so
 * a manual admin write must not be able to grant paid access for a payment that
 * never happened (finding PAY-3).
 */
class OrderStatusPaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_cannot_be_marked_paid(): void
    {
        $order = $this->orderWithStatus('pending');
        $this->bindStripeMockWithoutCalls();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('Rechnungskette', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_pending_payment_order_cannot_be_marked_paid(): void
    {
        $order = $this->orderWithStatus('pending_payment');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invoiceChainStatusProvider(): array
    {
        return [
            'invoice_created' => ['invoice_created'],
            'overdue' => ['overdue'],
            'delivery_note' => ['delivery_note'],
        ];
    }

    #[DataProvider('invoiceChainStatusProvider')]
    public function test_invoice_chain_status_can_be_marked_paid(string $status): void
    {
        $order = $this->orderWithStatus($status);
        $this->bindStripeMockWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_paid_order_can_be_rewritten_idempotently(): void
    {
        $order = $this->orderWithStatus('paid');
        $this->bindStripeMockWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_succeeded_payment_intent_allows_paid(): void
    {
        $order = $this->orderWithPaymentIntent('pi_paid_succeeded');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_paid_succeeded')
            ->willReturn(['status' => 'succeeded']);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_unsucceeded_payment_intent_is_rejected(): void
    {
        $order = $this->orderWithPaymentIntent('pi_paid_requires_payment_method');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_paid_requires_payment_method')
            ->willReturn(['status' => 'requires_payment_method']);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('von Stripe bestätigten Zahlung', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'refunded' => ['refunded'],
            'disputed' => ['disputed'],
        ];
    }

    /**
     * A refund leaves the Stripe PaymentIntent at `succeeded`, so the intent is
     * no proof that the money is still there. These states are rejected before
     * the lookup, hence `retrievePaymentIntent` must never be called.
     */
    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_status_cannot_be_marked_paid_even_with_a_succeeded_intent(string $status): void
    {
        $order = Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => $status,
            'stripe_payment_intent_id' => 'pi_'.$status.'_succeeded',
        ]);
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);
        $this->assertStringContainsString('wieder auf paid', $response->json('error'));
        $this->assertStringContainsString('neue Zahlung', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
    }

    public function test_stripe_lookup_failure_returns_502_and_keeps_order(): void
    {
        $order = $this->orderWithPaymentIntent('pi_paid_lookup_failure');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_paid_lookup_failure')
            ->willThrowException(new RuntimeException('Stripe API unavailable: sk_test_sensitive'));
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(502);
        $this->assertStringContainsString('bei Stripe nicht geprüft', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($order): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'order.paid.payment_intent_lookup_failed'
                    && $context['order_id'] === (string) $order->getKey()
                    && $context['payment_intent_id'] === 'pi_paid_lookup_failure'
                    && $context['exception_class'] === RuntimeException::class
                    && ! str_contains($encodedContext, 'sk_test_sensitive');
            })
            ->once();
    }

    public function test_rejected_transition_keeps_download_access_denied(): void
    {
        $order = $this->orderWithStatus('pending');
        $this->bindStripeMockWithoutCalls();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);

        // The finding is about access, not the status string.
        $this->assertFalse(app(PurchaseService::class)->isOrderDownloadEligible($order->fresh()));
    }

    public function test_rejected_transition_does_not_clear_the_purchase_cache(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending',
            'stripe_payment_intent_id' => null,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'PAID-GUARD-'.$order->getKey(),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [[
                    'photoId' => 'photo-paid-guard',
                    'tier' => 'original',
                    'price' => 1000,
                ]],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);
        $this->bindStripeMockWithoutCalls();
        $cacheKey = ActorIdentity::purchaseCacheKeyForActor($user, 'photo-paid-guard', 'original');
        $this->assertNotNull($cacheKey);
        Cache::put($cacheKey, true, 3600);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'paid'], $this->adminHeaders())
            ->assertStatus(422);

        // Nothing was written, so the snapshot-derived cache must survive; the
        // order's items are the only source the clear would have touched.
        $this->assertTrue(Cache::has($cacheKey));
    }

    private function orderWithStatus(string $status): Order
    {
        return Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => $status,
            'stripe_payment_intent_id' => null,
        ]);
    }

    private function orderWithPaymentIntent(string $paymentIntentId): Order
    {
        return Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);
    }

    private function bindStripeMockWithoutCalls(): void
    {
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);
    }

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ['Authorization' => 'Bearer '.auth('api')->login($admin)];
    }
}
