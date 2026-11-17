<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Support\BrandRegistry;
use App\Support\CheckoutKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The order identity (owner, brand, key, fingerprint, persisted amount) is the
 * only replay boundary: a repeated checkout attempt must reuse the persisted
 * order, invoice snapshot and provider attempt instead of creating new ones.
 */
class CheckoutPersistenceIntegrationTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie();
        BrandRegistry::set(Brand::B2B);
        config([
            'checkout.new_account_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_same_checkout_attempt_reuses_order_snapshot_and_payment_attempt(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);
        $request = $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0001');

        $first = $service->processCheckout($request, $user, 'mollie');
        $second = $service->processCheckout($request, $user, 'mollie');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, InvoiceSnapshot::count());
        $this->assertSame(1, MolliePaymentAttempt::count());
        $this->assertDatabaseHas('orders', [
            'id' => $first->getData(true)['order_id'],
            'status' => 'pending_payment',
            'checkout_idempotency_key' => 'checkout-persistence-0001',
        ]);
    }

    public function test_new_client_key_after_session_storage_loss_reuses_a_recent_pending_checkout_by_server_fingerprint(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0001'),
            $user,
            'mollie',
        );
        $originalOrder = Order::query()->firstOrFail();
        $originalKey = $originalOrder->checkout_idempotency_key;
        $originalFingerprint = $originalOrder->checkout_fingerprint;
        $replay = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0002'),
            $user,
            'mollie',
        );
        $originalKeyReplay = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-lost-key-0001'),
            $user,
            'mollie',
        );

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $replay->getStatusCode());
        $this->assertSame(200, $originalKeyReplay->getStatusCode());
        $this->assertSame($first->getData(true), $replay->getData(true));
        $this->assertSame($first->getData(true), $originalKeyReplay->getData(true));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, MolliePaymentAttempt::count());
        $this->assertSame($originalKey, $originalOrder->fresh()->checkout_idempotency_key);
        $this->assertSame($originalFingerprint, $originalOrder->fresh()->checkout_fingerprint);
    }

    public function test_changed_cart_does_not_match_lost_key_fingerprint_fallback(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-changed-cart-0001'),
            $user,
            'mollie',
        );
        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null, 'Street 2'),
            $user,
            'mollie',
        );

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(2, Order::count());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
    }

    public function test_paid_order_is_not_reused_when_the_browser_key_is_lost(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-paid-fallback-0001'),
            $user,
            'mollie',
        );
        $order = Order::query()->findOrFail($first->getData(true)['order_id']);
        $order->update(['status' => 'paid']);

        $second = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, null),
            $user,
            'mollie',
        );

        $this->assertSame(200, $second->getStatusCode());
        $this->assertNotSame($first->getData(true)['order_id'], $second->getData(true)['order_id']);
        $this->assertSame(2, Order::count());
    }

    public function test_same_key_with_changed_payload_returns_conflict_before_creating_another_attempt(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $first = $service->processCheckout(
            $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0002'),
            $user,
            'mollie',
        );
        $changedBilling = $this->checkoutRequest($photo, $useCase, 'checkout-persistence-0002', 'Other Street 9');
        $second = $service->processCheckout($changedBilling, $user, 'mollie');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
        $this->assertTrue($second->getData(true)['idempotency_conflict']);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, MolliePaymentAttempt::count());
    }

    public function test_exact_replay_is_resolved_before_turnstile_threshold_without_a_token(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $this->fakeMollie([
            CreatePaymentRequest::class => $this->mollieCreateResponder('open'),
        ], retainRequests: true);
        $service = new CheckoutService(new ScopeLicensingStrategy);
        $request = $this->checkoutRequest($photo, $useCase, 'checkout-replay-before-risk');

        $first = $service->processCheckout($request, $user, 'mollie');
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

        $replay = $service->processCheckout($request, $user, 'mollie');

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
            'payment_method' => 'mollie',
            'withdrawal_waived' => true,
        ], [], [], $server);
    }
}
