<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OrderStatusCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_payment_intent_is_cancelled_before_order_status_changes(): void
    {
        $order = $this->orderWithPaymentIntent('pi_open');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_open')
            ->willReturn(['status' => 'requires_payment_method']);
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_open')
            ->willReturn(['status' => 'canceled']);
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_already_canceled_payment_intent_is_not_canceled_twice(): void
    {
        $order = $this->orderWithPaymentIntent('pi_canceled');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_canceled')
            ->willReturn(['status' => 'canceled']);
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders())
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_succeeded_payment_intent_returns_502_without_changing_order(): void
    {
        $order = $this->orderWithPaymentIntent('pi_succeeded');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_succeeded')
            ->willReturn(['status' => 'succeeded']);
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders())
            ->assertStatus(502);
        $this->assertStringContainsString('bereits bezahlt', $response->json('error'));
        $this->assertStringContainsString('kein Erstattungsweg', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
    }

    public function test_throwing_cancel_returns_502_keeps_order_and_logs_safe_warning_context(): void
    {
        $order = $this->orderWithPaymentIntent('pi_cancel_failure');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_cancel_failure')
            ->willReturn(['status' => 'requires_payment_method']);
        $stripePayment->expects($this->once())
            ->method('cancelPaymentIntent')
            ->with('pi_cancel_failure')
            ->willThrowException(new RuntimeException(
                'Stripe API unavailable: sk_test_sensitive client_secret=pi_123_secret_sensitive Authorization: Bearer token_sensitive',
            ));
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $response = $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders())
            ->assertStatus(502);
        $this->assertStringContainsString('bei Stripe nicht abgeschlossen', $response->json('error'));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($order): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'order.cancel.payment_intent_not_cancelled'
                    && $context['order_id'] === (string) $order->getKey()
                    && $context['payment_intent_id'] === 'pi_cancel_failure'
                    && $context['exception_class'] === RuntimeException::class
                    && str_contains($context['exception_message'], 'Stripe API unavailable')
                    && ! str_contains($encodedContext, 'sk_test_sensitive')
                    && ! str_contains($encodedContext, 'pi_123_secret_sensitive')
                    && ! str_contains($encodedContext, 'token_sensitive')
                    && ! array_key_exists('exception_trace', $context);
            })
            ->once();
    }

    public function test_retrieval_failure_returns_502_keeps_order_and_logs_warning(): void
    {
        $order = $this->orderWithPaymentIntent('pi_retrieve_failure');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->once())
            ->method('retrievePaymentIntent')
            ->with('pi_retrieve_failure')
            ->willThrowException(new RuntimeException('Stripe retrieval unavailable'));
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);
        Log::spy();

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders())
            ->assertStatus(502);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending_payment']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'order.cancel.payment_intent_not_cancelled'
                && $context['order_id'] === (string) $order->getKey()
                && $context['payment_intent_id'] === 'pi_retrieve_failure'
                && $context['exception_class'] === RuntimeException::class
                && $context['exception_message'] === 'Stripe retrieval unavailable')
            ->once();
    }

    public function test_order_without_payment_intent_id_is_canceled_without_stripe_calls(): void
    {
        $orders = [
            Order::factory()->create([
                'brand' => Brand::B2B,
                'status' => 'pending_payment',
                'stripe_payment_intent_id' => null,
            ]),
            Order::factory()->create([
                'brand' => Brand::B2B,
                'status' => 'pending_payment',
                'stripe_payment_intent_id' => '',
            ]),
        ];
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);
        $headers = $this->adminHeaders();

        foreach ($orders as $order) {
            $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'cancelled'], $headers)
                ->assertOk();

            $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        }
    }

    public function test_non_cancelled_status_keeps_existing_behavior_without_stripe_calls(): void
    {
        $order = $this->orderWithPaymentIntent('pi_non_cancelled_target');
        $stripePayment = $this->createMock(StripePaymentService::class);
        $stripePayment->expects($this->never())->method('retrievePaymentIntent');
        $stripePayment->expects($this->never())->method('cancelPaymentIntent');
        $this->app->instance(StripePaymentService::class, $stripePayment);

        $this->putJson("/api/management/orders/{$order->id}/status", ['status' => 'overdue'], $this->adminHeaders())
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'overdue']);
    }

    private function orderWithPaymentIntent(string $paymentIntentId): Order
    {
        return Order::factory()->create([
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);
    }

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return ['Authorization' => 'Bearer '.auth('api')->login($admin)];
    }
}
