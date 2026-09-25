<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Mail\CustomMail;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutIdempotencyService;
use App\Services\CheckoutService;
use App\Services\StripePaymentService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class CheckoutStripeErrorTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        $this->service = new CheckoutService(new VolumeLicensingStrategy(
            app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B)
        ));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        BrandRegistry::set(null);
        parent::tearDown();
    }

    #[DataProvider('invalidInitialResponseProvider')]
    public function test_incomplete_or_mismatched_initial_pi_response_fails_closed(string $mutation): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('createPaymentIntent')
            ->willReturnCallback(function (
                int $amount,
                string $orderId,
                string $receiptEmail,
                ?string $customerId,
                int $generation,
                int|string $userId,
                int|string|null $accountCreatedAt,
                ?string $checkoutKey,
                ?string $checkoutFingerprint,
            ) use ($mutation): array {
                $order = Order::query()->findOrFail($orderId);
                $response = [
                    'id' => 'pi_initial_invalid',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_initial_invalid_secret',
                    'amount' => $amount,
                    'currency' => 'eur',
                    'created' => time(),
                    'customer' => $customerId,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'portal_user_id' => (string) $userId,
                        'account_created_at' => (string) $accountCreatedAt,
                        'checkout_idempotency_key' => (string) $checkoutKey,
                        'checkout_fingerprint' => (string) $checkoutFingerprint,
                        'generation' => (string) $generation,
                        'amount_cents' => (string) $amount,
                        'currency' => 'eur',
                    ],
                ];

                return match ($mutation) {
                    'missing_id' => array_replace($response, ['id' => '']),
                    'missing_client_secret' => array_replace($response, ['client_secret' => null]),
                    'terminal_status' => array_replace($response, ['status' => 'succeeded']),
                    'amount_mismatch' => array_replace($response, ['amount' => $amount + 1]),
                    'missing_created' => array_replace($response, ['created' => null]),
                    'zero_created' => array_replace($response, ['created' => 0]),
                    'currency_mismatch' => array_replace($response, ['currency' => 'usd']),
                    'missing_portal_user' => array_replace($response, ['metadata' => array_diff_key($response['metadata'], ['portal_user_id' => true])]),
                    'missing_key' => array_replace($response, ['metadata' => array_diff_key($response['metadata'], ['checkout_idempotency_key' => true])]),
                    'missing_fingerprint' => array_replace($response, ['metadata' => array_diff_key($response['metadata'], ['checkout_fingerprint' => true])]),
                    'missing_generation' => array_replace($response, ['metadata' => array_diff_key($response['metadata'], ['generation' => true])]),
                    'customer_mismatch' => array_replace($response, ['customer' => 'cus_unexpected']),
                    default => $response,
                };
            });
        $service = new CheckoutService(new VolumeLicensingStrategy(
            app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B)
        ), $stripe);

        $response = $service->processCheckout(
            $this->checkoutRequest('browser-key-invalid-'.$mutation),
            $user,
            'stripe',
        );
        $order = Order::query()->firstOrFail();

        $this->assertSame(502, $response->getStatusCode());
        $this->assertArrayNotHasKey('client_secret', $response->getData(true));
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->stripe_payment_intent_id);
        $this->assertSame(1, $order->payment_intent_generation);
    }

    public static function invalidInitialResponseProvider(): array
    {
        return array_map(
            static fn (string $mutation): array => [$mutation],
            [
                'missing_id',
                'missing_client_secret',
                'terminal_status',
                'amount_mismatch',
                'missing_created',
                'zero_created',
                'currency_mismatch',
                'missing_portal_user',
                'missing_key',
                'missing_fingerprint',
                'missing_generation',
                'customer_mismatch',
            ],
        );
    }

    public function test_valid_initial_pi_response_validates_mapped_customer_before_persisting(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_expected_initial']);
        $user->galleries()->attach($gallery->id);
        config(['app.stripe.customers_enabled' => false]);

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('createPaymentIntent')
            ->willReturnCallback(function (
                int $amount,
                string $orderId,
                string $receiptEmail,
                ?string $customerId,
                int $generation,
                int|string $userId,
                int|string|null $accountCreatedAt,
                ?string $checkoutKey,
                ?string $checkoutFingerprint,
            ): array {
                $order = Order::query()->findOrFail($orderId);
                $this->assertSame('cus_expected_initial', $customerId);

                return [
                    'id' => 'pi_valid_initial',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_valid_initial_secret',
                    'amount' => $amount,
                    'currency' => 'eur',
                    'created' => time(),
                    'customer' => 'cus_expected_initial',
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'portal_user_id' => (string) $userId,
                        'account_created_at' => (string) $accountCreatedAt,
                        'checkout_idempotency_key' => (string) $checkoutKey,
                        'checkout_fingerprint' => (string) $checkoutFingerprint,
                        'generation' => (string) $generation,
                        'amount_cents' => (string) $amount,
                        'currency' => 'eur',
                    ],
                ];
            });
        $service = new CheckoutService(new VolumeLicensingStrategy(
            app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B)
        ), $stripe);

        $response = $service->processCheckout(
            $this->checkoutRequest('browser-key-valid-initial'),
            $user,
            'stripe',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pi_valid_initial_secret', $response->getData(true)['client_secret']);
        $this->assertDatabaseHas('orders', [
            'id' => $response->getData(true)['order_id'],
            'stripe_payment_intent_id' => 'pi_valid_initial',
            'status' => 'pending_payment',
        ]);
    }

    public function test_payment_creator_lock_guard_does_not_reopen_an_order_paid_after_preparation(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);
        $request = $this->checkoutRequest('creator-race-key-0001');
        $stripe = $this->createMock(StripePaymentService::class);
        $identityService = new CheckoutIdempotencyService($stripe);
        $identity = $identityService->identify($request, $user, 4000, 'stripe');
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'total_amount' => 4000,
            'stripe_payment_intent_id' => 'pi_creator_race_old',
            'checkout_idempotency_key' => $identity['key'],
            'checkout_fingerprint' => $identity['fingerprint'],
            'payment_intent_generation' => 1,
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $idempotency = $this->getMockBuilder(CheckoutIdempotencyService::class)
            ->setConstructorArgs([$stripe])
            ->onlyMethods(['execute'])
            ->getMock();
        $idempotency->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (
                User $user,
                string $key,
                string $fingerprint,
                int $amountCents,
                \Closure $creator,
                bool $allowFingerprintFallback,
            ) use ($order): JsonResponse {
                Order::query()->whereKey($order->getKey())->update(['status' => 'paid']);

                return $creator($order->fresh());
            });
        $stripe->expects($this->never())->method('createPaymentIntent');
        $service = new CheckoutService(
            new VolumeLicensingStrategy(
                app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B)
            ),
            $stripe,
            null,
            null,
            null,
            $idempotency,
        );

        $response = $service->processCheckout($request, $user, 'stripe');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('client_secret', $response->getData(true));
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('pi_creator_race_old', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_ambiguous_payment_intent_create_can_retry_same_generation_without_duplicate_pi(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        Log::spy();
        $call = 0;
        $idempotencyHeaders = [];
        $metadata = [];
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use (&$call, &$idempotencyHeaders, &$metadata): array {
                $call++;
                $metadata[] = $parameters['metadata'] ?? [];
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                        $idempotencyHeaders[] = $header;
                    }
                }
                if ($call === 1) {
                    throw new \RuntimeException('timeout client_secret=must_not_be_logged');
                }

                return [json_encode([
                    'id' => 'pi_retry_same_generation',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_retry_same_generation_secret',
                    'amount' => (int) ($parameters['amount'] ?? 0),
                    'currency' => $parameters['currency'] ?? 'eur',
                    'created' => time(),
                    'customer' => $parameters['customer'] ?? null,
                    'metadata' => $parameters['metadata'] ?? [],
                ]), 200, []];
            });
        ApiRequestor::setHttpClient($clientMock);

        $payload = [
            'items' => [['photoId' => $photo->id, 'tier' => 'srp']],
            'billing_name' => 'Test User',
            'billing_company' => null,
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ];
        $firstRequest = Request::create('/', 'POST', $payload, [], [], ['REMOTE_ADDR' => '198.51.100.61']);
        $firstRequest->headers->set('Idempotency-Key', 'browser-key-ambiguous-0001');
        $retryRequest = Request::create('/', 'POST', $payload, [], [], ['REMOTE_ADDR' => '198.51.100.62']);

        $first = $this->service->processCheckout($firstRequest, $user, 'stripe');
        $originalOrder = Order::first();
        $this->assertNotNull($originalOrder);
        $originalKey = $originalOrder->checkout_idempotency_key;
        $originalFingerprint = $originalOrder->checkout_fingerprint;
        $second = $this->service->processCheckout($retryRequest, $user, 'stripe');

        $this->assertSame(502, $first->status());
        $this->assertSame(200, $second->status());
        $this->assertCount(2, $idempotencyHeaders);
        $this->assertSame($idempotencyHeaders[0], $idempotencyHeaders[1]);
        $this->assertSame($originalKey, $metadata[1]['checkout_idempotency_key']);
        $this->assertSame($originalFingerprint, $metadata[1]['checkout_fingerprint']);
        $this->assertDatabaseHas('orders', [
            'status' => 'pending_payment',
            'payment_intent_generation' => 1,
            'stripe_payment_intent_id' => 'pi_retry_same_generation',
        ]);
        $persistedOrder = Order::first();
        $this->assertSame($originalKey, $persistedOrder->checkout_idempotency_key);
        $this->assertSame($originalFingerprint, $persistedOrder->checkout_fingerprint);
        $this->assertSame('198.51.100.61', $persistedOrder->ip_address);
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'Stripe payment failed for order {order_id}'
                    && ! str_contains($encoded, 'client_secret')
                    && ! str_contains($encoded, 'must_not_be_logged');
            })
            ->once();
    }

    public function test_missing_client_secret_is_retryable_without_persisting_a_payment_intent(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $idempotencyHeaders = [];
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use (&$idempotencyHeaders): array {
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                        $idempotencyHeaders[] = $header;
                    }
                }

                return [
                    json_encode([
                        'id' => 'pi_missing_client_secret',
                        'status' => 'requires_payment_method',
                        'client_secret' => null,
                        'amount' => (int) ($parameters['amount'] ?? 0),
                        'currency' => $parameters['currency'] ?? 'eur',
                        'created' => time(),
                        'customer' => $parameters['customer'] ?? null,
                        'metadata' => $parameters['metadata'] ?? [],
                    ]),
                    200,
                    [],
                ];
            });
        ApiRequestor::setHttpClient($clientMock);

        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => $photo->id, 'tier' => 'srp']],
            'billing_name' => 'Test User',
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ]);
        $request->headers->set('Idempotency-Key', 'browser-key-missing-secret-0001');

        $response = $this->service->processCheckout($request, $user, 'stripe');
        $order = Order::first();

        $this->assertSame(502, $response->status());
        $this->assertCount(1, $idempotencyHeaders);
        $this->assertStringStartsWith('Idempotency-Key: pi_', $idempotencyHeaders[0]);
        $this->assertStringEndsWith('_1', $idempotencyHeaders[0]);
        $this->assertNotNull($order);
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->stripe_payment_intent_id);
        $this->assertSame(1, $order->payment_intent_generation);
    }

    public function test_missing_created_timestamp_retries_with_the_same_generation_and_key(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);
        $idempotencyHeaders = [];
        $call = 0;
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use (&$idempotencyHeaders, &$call): array {
                $call++;
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                        $idempotencyHeaders[] = $header;
                    }
                }

                return [json_encode([
                    'id' => 'pi_created_retry',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_created_retry_secret',
                    'amount' => (int) ($parameters['amount'] ?? 0),
                    'amount_received' => null,
                    'currency' => 'eur',
                    'created' => $call === 1 ? null : time(),
                    'customer' => $parameters['customer'] ?? null,
                    'metadata' => $parameters['metadata'] ?? [],
                ]), 200, []];
            });
        ApiRequestor::setHttpClient($clientMock);

        $request = $this->checkoutRequest('created-retry-key-0001');
        $first = $this->service->processCheckout($request, $user, 'stripe');
        $second = $this->service->processCheckout($request, $user, 'stripe');
        $order = Order::query()->firstOrFail();

        $this->assertSame(502, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertCount(2, $idempotencyHeaders);
        $this->assertSame($idempotencyHeaders[0], $idempotencyHeaders[1]);
        $this->assertSame(1, $order->payment_intent_generation);
        $this->assertSame('pi_created_retry', $order->stripe_payment_intent_id);
    }

    public function test_accounting_mail_failure_does_not_mask_the_retryable_payment_error(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $originalMail = Mail::getFacadeRoot();
        $mailManager = \Mockery::mock(MailManager::class);
        $mailManager->shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('mail transport unavailable'));
        Mail::swap($mailManager);

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Stripe unavailable'));
        ApiRequestor::setHttpClient($clientMock);

        try {
            $response = $this->service->processCheckout(
                $this->checkoutRequest('mail-failure-does-not-mask-0001'),
                $user,
                'stripe',
            );

            $this->assertSame(502, $response->getStatusCode());
            $this->assertDatabaseHas('orders', [
                'status' => 'pending_payment',
                'stripe_payment_intent_id' => null,
            ]);
        } finally {
            Mail::swap($originalMail);
        }
    }

    public function test_stripe_failure_keeps_order_pending_for_same_generation_retry_and_sends_accounting_mail(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())->method('request')
            ->willThrowException(new \RuntimeException('Stripe down'));
        ApiRequestor::setHttpClient($clientMock);

        $request = Request::create('/', 'POST', [
            'items' => [['photoId' => $photo->id, 'tier' => 'srp']],
            'billing_name' => 'Test User',
            'billing_company' => null,
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ]);

        $response = $this->service->processCheckout($request, $user, 'stripe');

        $this->assertEquals(502, $response->status());
        $payload = $response->getData(true);
        $this->assertArrayHasKey('error', $payload);

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame('pending_payment', $order->status);

        Mail::assertQueued(CustomMail::class, 1);
    }

    private function checkoutRequest(string $idempotencyKey): Request
    {
        return Request::create('/', 'POST', [
            'items' => [['photoId' => Photo::query()->value('id'), 'tier' => 'srp']],
            'billing_name' => 'Test User',
            'billing_street' => 'Teststr. 1',
            'billing_zip' => '1010',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ], [], [], [
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ]);
    }
}
