<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

class StripePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_it_creates_a_generation_aware_intent_with_customer_and_safe_metadata(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.test']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'payment_intent_generation' => 7,
            'checkout_idempotency_key' => 'checkout-key-payment-service',
            'checkout_fingerprint' => str_repeat('f', 64),
            'total_amount' => 3456,
        ]);
        $accountCreatedAt = $user->created_at->getTimestamp();

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use ($order, $user, $accountCreatedAt): array {
                $this->assertSame('post', $method);
                $this->assertSame('https://api.stripe.com/v1/payment_intents', $url);
                $this->assertSame(3456, $parameters['amount']);
                $this->assertSame('eur', $parameters['currency']);
                $this->assertSame('payer@example.test', $parameters['receipt_email']);
                $this->assertSame('cus_for_order', $parameters['customer']);
                $this->assertSame([
                    'order_id' => (string) $order->getKey(),
                    'portal_user_id' => (string) $user->getKey(),
                    'generation' => '7',
                    'account_created_at' => (string) $accountCreatedAt,
                    'checkout_idempotency_key' => 'checkout-key-payment-service',
                    'checkout_fingerprint' => str_repeat('f', 64),
                    'amount_cents' => '3456',
                    'currency' => 'eur',
                ], $parameters['metadata']);
                $this->assertArrayNotHasKey('payment_method_types', $parameters);
                $this->assertContains(
                    'Idempotency-Key: pi_'.$order->getKey().'_7',
                    $headers,
                );

                return [
                    json_encode([
                        'id' => 'pi_generation_7',
                        'object' => 'payment_intent',
                        'status' => 'requires_payment_method',
                        'client_secret' => 'pi_generation_7_secret_test',
                        'amount' => 3456,
                        'currency' => 'eur',
                        'created' => 1234567890,
                        'customer' => 'cus_for_order',
                        'metadata' => [
                            'order_id' => (string) $order->getKey(),
                            'portal_user_id' => (string) $user->getKey(),
                            'account_created_at' => (string) $accountCreatedAt,
                            'checkout_idempotency_key' => 'checkout-key-payment-service',
                            'checkout_fingerprint' => str_repeat('f', 64),
                            'generation' => '7',
                            'amount_cents' => '3456',
                            'currency' => 'eur',
                        ],
                        'payment_method' => 'pm_sensitive_value',
                    ], JSON_THROW_ON_ERROR),
                    200,
                    [],
                ];
            });
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));
        $result = $service->createPaymentIntent(
            3456,
            (string) $order->getKey(),
            (string) $user->email,
            'cus_for_order',
            7,
            (string) $user->getKey(),
            $accountCreatedAt,
            'checkout-key-payment-service',
            str_repeat('f', 64),
        );

        $this->assertSame([
            'id' => 'pi_generation_7',
            'status' => 'requires_payment_method',
            'client_secret' => 'pi_generation_7_secret_test',
            'amount' => 3456,
            'amount_received' => null,
            'currency' => 'eur',
            'created' => 1234567890,
            'customer' => 'cus_for_order',
            'metadata' => [
                'order_id' => (string) $order->getKey(),
                'portal_user_id' => (string) $user->getKey(),
                'account_created_at' => (string) $accountCreatedAt,
                'checkout_idempotency_key' => 'checkout-key-payment-service',
                'checkout_fingerprint' => str_repeat('f', 64),
                'generation' => '7',
                'amount_cents' => '3456',
                'currency' => 'eur',
            ],
        ], $result);
        $this->assertArrayNotHasKey('payment_method', $result);
        $this->assertArrayNotHasKey('latest_charge', $result);
        $this->assertSame('cus_for_order', $result['customer']);
        $this->assertSame((string) $user->getKey(), $result['metadata']['portal_user_id']);
    }

    public function test_legacy_create_signature_resolves_order_context_and_omits_optional_customer(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'payment_intent_generation' => 3,
            'total_amount' => 1000,
        ]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use ($order, $user): array {
                $this->assertArrayNotHasKey('customer', $parameters);
                $this->assertSame((string) $user->getKey(), $parameters['metadata']['portal_user_id']);
                $this->assertSame('3', $parameters['metadata']['generation']);
                $this->assertSame((string) $user->created_at->getTimestamp(), $parameters['metadata']['account_created_at']);
                $this->assertContains(
                    'Idempotency-Key: pi_'.$order->getKey(),
                    $headers,
                );

                return [
                    json_encode([
                        'id' => 'pi_legacy',
                        'object' => 'payment_intent',
                        'status' => 'requires_payment_method',
                        'client_secret' => 'pi_legacy_secret_test',
                        'amount' => 1000,
                        'currency' => 'eur',
                        'metadata' => ['order_id' => (string) $order->getKey()],
                    ], JSON_THROW_ON_ERROR),
                    200,
                    [],
                ];
            });
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));
        $result = $service->createPaymentIntent(1000, (string) $order->getKey(), (string) $user->email);

        $this->assertSame('pi_legacy', $result['id']);
    }

    public function test_legacy_missing_order_fails_closed_before_calling_stripe(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));
        $this->expectException(\RuntimeException::class);
        $service->createPaymentIntent(1000, 'missing-order', 'legacy@example.test');
    }

    public function test_amount_mismatch_fails_closed_before_calling_stripe(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 5000,
        ]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));

        try {
            $service->createPaymentIntent(4999, (string) $order->getKey(), (string) $user->email);
            $this->fail('Expected the amount mismatch to be rejected before any Stripe call.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Stripe PaymentIntent amount does not match the persisted order total.',
                $exception->getMessage(),
            );
        }
    }

    public function test_fee_retrieval_distinguishes_unavailable_from_genuine_zero(): void
    {
        $call = 0;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function () use (&$call): array {
                $call++;
                $body = $call === 1
                    ? ['id' => 'pi_fee_missing', 'object' => 'payment_intent']
                    : [
                        'id' => 'pi_fee_zero',
                        'object' => 'payment_intent',
                        'latest_charge' => ['balance_transaction' => ['fee' => 0]],
                    ];

                return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
            });
        ApiRequestor::setHttpClient($httpClient);
        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));

        $this->assertNull($service->retrievePaymentIntentWithFee('pi_fee_missing'));
        $this->assertSame(0, $service->retrievePaymentIntentWithFee('pi_fee_zero'));
    }

    public function test_it_retrieves_a_whitelisted_scalar_payment_intent_view(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url): array {
                $this->assertSame('get', $method);
                $this->assertSame('https://api.stripe.com/v1/payment_intents/pi_retrieve', $url);

                return [
                    json_encode([
                        'id' => 'pi_retrieve',
                        'object' => 'payment_intent',
                        'status' => 'processing',
                        'client_secret' => 'pi_retrieve_secret_test',
                        'amount' => 2999,
                        'currency' => 'eur',
                        'created' => 1234567890,
                        'metadata' => [
                            'order_id' => 'order-retrieve',
                            'portal_user_id' => 'must-not-leak',
                        ],
                        'receipt_number' => 'must-not-leak',
                    ], JSON_THROW_ON_ERROR),
                    200,
                    [],
                ];
            });
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripePaymentService(new StripeClient('test-server-side-placeholder'));

        $retrieved = $service->retrievePaymentIntent('pi_retrieve');
        $this->assertSame([
            'id' => 'pi_retrieve',
            'status' => 'processing',
            'client_secret' => 'pi_retrieve_secret_test',
            'amount' => 2999,
            'amount_received' => null,
            'currency' => 'eur',
            'created' => 1234567890,
            'customer' => null,
            'metadata' => [
                'order_id' => 'order-retrieve',
                'portal_user_id' => 'must-not-leak',
            ],
        ], $retrieved);
        $this->assertArrayNotHasKey('payment_method', $retrieved);
        $this->assertArrayNotHasKey('latest_charge', $retrieved);
    }
}
