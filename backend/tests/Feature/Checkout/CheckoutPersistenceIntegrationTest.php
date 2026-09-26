<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutIdempotencyService;
use App\Services\CheckoutService;
use App\Services\StripePaymentService;
use App\Support\BrandRegistry;
use App\Support\CheckoutKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

class CheckoutPersistenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        config([
            'app.stripe.checkout_new_account_hours' => 24,
            'app.stripe.customers_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_same_checkout_attempt_reuses_order_snapshot_and_payment_intent(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock();
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);
        $request = $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0001');

        $first = $service->processCheckout($request, $user, 'stripe');
        $second = $service->processCheckout($request, $user, 'stripe');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, InvoiceSnapshot::count());
        $this->assertDatabaseHas('orders', [
            'id' => $first->getData(true)['order_id'],
            'status' => 'pending_payment',
            'checkout_idempotency_key' => 'checkout-persistence-0001',
            'payment_intent_generation' => 1,
            'stripe_payment_intent_id' => 'pi_persistence_1',
        ]);
    }

    public function test_new_client_key_after_session_storage_loss_reuses_a_recent_pending_checkout_by_server_fingerprint(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock(true, 1, 2);
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0001'),
            $user,
            'stripe',
        );
        $originalOrder = Order::query()->firstOrFail();
        $originalKey = $originalOrder->checkout_idempotency_key;
        $originalFingerprint = $originalOrder->checkout_fingerprint;
        $replay = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0002'),
            $user,
            'stripe',
        );
        $originalKeyReplay = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0001'),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame(200, $originalKeyReplay->getStatusCode());
        $this->assertSame($first->getData(true), $replay->getData(true));
        $this->assertSame($first->getData(true), $originalKeyReplay->getData(true));
        $this->assertSame(1, Order::count());
        $this->assertSame($originalKey, $originalOrder->fresh()->checkout_idempotency_key);
        $this->assertSame($originalFingerprint, $originalOrder->fresh()->checkout_fingerprint);
    }

    public function test_lost_key_null_pi_retry_uses_the_persisted_identity_and_generation(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = new StripePaymentService(new StripeClient('test-server-side-placeholder'));
        $identityService = new CheckoutIdempotencyService($stripe);
        $recoveryRequest = $this->checkoutRequest($photo, $useCase, 'new-client-key-0001');
        $identity = $identityService->identify($recoveryRequest, $user, 4000, 'stripe');
        $persistedKey = 'persisted-checkout-key-0001';
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
            'status' => 'pending_payment',
            'total_amount' => 4000,
            'stripe_payment_intent_id' => null,
            'checkout_idempotency_key' => $persistedKey,
            'checkout_fingerprint' => $identity['fingerprint'],
            'payment_intent_generation' => 1,
        ]);
        InvoiceSnapshot::factory()->for($order)->create();

        $idempotencyHeader = null;
        $metadata = null;
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters) use (&$idempotencyHeader, &$metadata): array {
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: ')) {
                        $idempotencyHeader = $header;
                    }
                }
                $metadata = $parameters['metadata'] ?? [];

                return [json_encode([
                    'id' => 'pi_lost_key_retry',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_lost_key_retry_secret',
                    'amount' => (int) ($parameters['amount'] ?? 0),
                    'amount_received' => null,
                    'currency' => 'eur',
                    'created' => time(),
                    'customer' => $parameters['customer'] ?? null,
                    'metadata' => $metadata,
                ]), 200, []];
            });
        ApiRequestor::setHttpClient($client);
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $response = $service->processCheckout($recoveryRequest, $user, 'stripe');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Idempotency-Key: pi_'.$order->id.'_1', $idempotencyHeader);
        $this->assertSame($persistedKey, $metadata['checkout_idempotency_key'] ?? null);
        $this->assertSame($identity['fingerprint'], $metadata['checkout_fingerprint'] ?? null);
        $this->assertSame($persistedKey, $order->fresh()->checkout_idempotency_key);
        $this->assertSame($identity['fingerprint'], $order->fresh()->checkout_fingerprint);
        $this->assertSame(1, $order->fresh()->payment_intent_generation);
    }

    public function test_changed_cart_does_not_match_lost_key_fingerprint_fallback(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock(false, 2);
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-changed-cart-0001'),
            $user,
            'stripe',
        );
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null, 'Street 2'),
            $user,
            'stripe',
        );

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(2, Order::count());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
    }

    public function test_paid_order_is_not_reused_when_the_browser_key_is_lost(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock(false, 2);
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-paid-fallback-0001'),
            $user,
            'stripe',
        );
        $order = Order::query()->findOrFail($first->getData(true)['order_id']);
        $order->update(['status' => 'paid']);

        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null),
            $user,
            'stripe',
        );

        $this->assertSame(200, $second->getStatusCode());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
        $this->assertSame(2, Order::count());
    }

    public function test_same_key_with_changed_payload_returns_conflict_before_creating_another_intent(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock(false);
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0002'),
            $user,
            'stripe',
        );
        $changedBilling = $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0002', 'Other Street 9');
        $second = $service->processCheckout($changedBilling, $user, 'stripe');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
        $this->assertTrue($second->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::count());
    }

    public function test_exact_replay_is_resolved_before_turnstile_threshold_without_a_token(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->stripeMock();
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);
        $request = $this->checkoutRequest($photo, $useCase, 'checkout-replay-before-risk');

        $first = $service->processCheckout($request, $user, 'stripe');
        $this->assertSame(200, $first->getStatusCode());

        config([
            'services.turnstile.site_key' => 'site-key',
            'services.turnstile.secret' => 'secret-key',
            'services.turnstile.allowed_hostnames' => 'checkout.example.test',
            'app.turnstile_user_threshold_per_hour' => 1,
            'app.turnstile_ip_threshold_per_hour' => 100,
        ]);
        RateLimiter::hit(CheckoutKey::user($user->getAuthIdentifier(), 'checkout-risk-attempt'), 3600);
        Http::fake();

        $replay = $service->processCheckout($request, $user, 'stripe');

        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame($first->getData(true), $replay->getData(true));
        Http::assertNothingSent();
    }

    private function checkoutData(): array
    {
        $user = User::factory()->create(['created_at' => now()->subDay()]);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Web',
            'base_price' => 4000,
            'flatrate_tier' => 'web',
            'brand' => Brand::B2B,
        ]);

        return [$user, $photo, $useCase];
    }

    private function checkoutRequest(
        Photo $photo,
        LicenseUseCase $useCase,
        ?string $idempotencyKey,
        string $street = 'Street 1',
    ): Request {
        $server = $idempotencyKey === null
            ? []
            : ['HTTP_IDEMPOTENCY_KEY' => $idempotencyKey];

        return Request::create('/', 'POST', [
            'items' => [[
                'photoId' => $photo->id,
                'tier' => 'web',
                'useCaseId' => $useCase->id,
                'modifierIds' => [],
            ]],
            'billing_name' => 'Test User',
            'billing_company' => null,
            'billing_street' => $street,
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'payment_method' => 'stripe',
            'withdrawal_waived' => true,
        ], [], [], $server);
    }

    private function stripeMock(bool $expectRetrieve = true, int $createCount = 1, ?int $retrieveCount = null): StripePaymentService
    {
        $stripe = $this->createMock(StripePaymentService::class);
        $createCall = 0;
        $stripe->expects($this->exactly($createCount))
            ->method('createPaymentIntent')
            ->willReturnCallback(function (int $amount, string $orderId) use (&$createCall): array {
                $createCall++;
                $order = Order::query()->findOrFail($orderId);

                return [
                    'id' => 'pi_persistence_'.$createCall,
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_persistence_'.$createCall.'_secret',
                    'amount' => (int) $order->total_amount,
                    'currency' => 'eur',
                    'created' => now()->getTimestamp(),
                    'customer' => null,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'portal_user_id' => (string) $order->user_id,
                        'account_created_at' => (string) $order->user->created_at->getTimestamp(),
                        'checkout_idempotency_key' => (string) $order->checkout_idempotency_key,
                        'checkout_fingerprint' => (string) $order->checkout_fingerprint,
                        'generation' => (string) $order->payment_intent_generation,
                        'amount_cents' => (string) $order->total_amount,
                        'currency' => 'eur',
                    ],
                ];
            });
        $retrieveExpectation = $expectRetrieve
            ? $this->exactly($retrieveCount ?? 1)
            : $this->never();
        $stripe->expects($retrieveExpectation)
            ->method('retrievePaymentIntent')
            ->with('pi_persistence_1')
            ->willReturnCallback(fn (string $id) => [
                'id' => $id,
                'status' => 'requires_payment_method',
                'client_secret' => $id.'_secret',
                'amount' => 4000,
                'currency' => 'eur',
                'created' => now()->getTimestamp(),
                'metadata' => [
                    'order_id' => (string) Order::first()->id,
                    'portal_user_id' => (string) Order::first()->user_id,
                    'account_created_at' => (string) Order::first()->user->created_at->getTimestamp(),
                    'checkout_idempotency_key' => (string) Order::first()->checkout_idempotency_key,
                    'checkout_fingerprint' => (string) Order::first()->checkout_fingerprint,
                    'generation' => (string) Order::first()->payment_intent_generation,
                    'amount_cents' => (string) Order::first()->total_amount,
                    'currency' => 'eur',
                ],
                'customer' => null,
            ]);

        return $stripe;
    }
}
