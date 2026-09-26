<?php

namespace Tests\Feature;

use App\Contracts\PricingStrategy;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Services\CheckoutRiskService;
use App\Services\CheckoutService;
use App\Services\StripePaymentService;
use App\Support\CheckoutKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\MocksStripeClient;
use Tests\TestCase;

class CheckoutRateLimitTest extends TestCase
{
    use MocksStripeClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_checkout_route_defers_the_named_checkout_limiter_until_service_classification(): void
    {
        $checkoutRoute = Route::getRoutes()->getByName('api.orders.checkout');
        $ordersRoute = Route::getRoutes()->getByName('api.orders.index');

        $this->assertNotNull($checkoutRoute);
        $this->assertNotNull($ordersRoute);
        $this->assertContains('POST', $checkoutRoute->methods());
        $this->assertNotContains('throttle:checkout', $checkoutRoute->gatherMiddleware());
        $this->assertContains('throttle:api', $checkoutRoute->gatherMiddleware());
        $this->assertNotContains('throttle:checkout', $ordersRoute->gatherMiddleware());
    }

    public function test_checkout_limiter_has_separate_user_ip_hour_and_ip_day_bounds(): void
    {
        config([
            'app.checkout_throttle_user_per_hour' => 5,
            'app.checkout_throttle_ip_per_hour' => 10,
            'app.checkout_throttle_ip_per_day' => 30,
        ]);

        $user = User::factory()->create();
        $request = Request::create(
            '/api/orders/checkout',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.40']
        );
        $request->setUserResolver(fn (?string $guard = null) => $user);

        $limiter = RateLimiter::limiter('checkout');
        $this->assertNotNull($limiter);
        $limits = $limiter($request);

        $this->assertCount(3, $limits);
        $this->assertSame(5, $limits[0]->maxAttempts);
        $this->assertSame(3600, $limits[0]->decaySeconds);
        $this->assertSame(
            CheckoutKey::user($user->getAuthIdentifier(), 'checkout-quota'),
            $limits[0]->key,
        );
        $this->assertSame(10, $limits[1]->maxAttempts);
        $this->assertSame(3600, $limits[1]->decaySeconds);
        $this->assertSame(
            CheckoutKey::ip('198.51.100.40', 'checkout-quota-hour'),
            $limits[1]->key,
        );
        $this->assertSame(30, $limits[2]->maxAttempts);
        $this->assertSame(86400, $limits[2]->decaySeconds);
        $this->assertSame(
            CheckoutKey::ip('198.51.100.40', 'checkout-quota-day'),
            $limits[2]->key,
        );
    }

    public function test_checkout_user_hour_quota_returns_429_after_limit(): void
    {
        config([
            'app.checkout_throttle_user_per_hour' => 2,
            'app.checkout_throttle_ip_per_hour' => 100,
            'app.checkout_throttle_ip_per_day' => 100,
        ]);
        $user = User::factory()->create();
        $service = app(CheckoutRiskService::class);
        $request = $this->requestFromIp('198.51.100.41');

        $service->assertCheckoutQuotaAllowed($request, $user);
        $service->assertCheckoutQuotaAllowed($request, $user);
        $this->assertQuotaDenied(fn () => $service->assertCheckoutQuotaAllowed($request, $user));
    }

    public function test_checkout_ip_hour_quota_is_shared_across_users(): void
    {
        config([
            'app.checkout_throttle_user_per_hour' => 100,
            'app.checkout_throttle_ip_per_hour' => 2,
            'app.checkout_throttle_ip_per_day' => 100,
        ]);
        $service = app(CheckoutRiskService::class);
        $request = $this->requestFromIp('198.51.100.42');
        $users = User::factory()->count(3)->create();

        $service->assertCheckoutQuotaAllowed($request, $users[0]);
        $service->assertCheckoutQuotaAllowed($request, $users[1]);
        $this->assertQuotaDenied(fn () => $service->assertCheckoutQuotaAllowed($request, $users[2]));
    }

    public function test_checkout_ip_day_quota_is_shared_across_users(): void
    {
        config([
            'app.checkout_throttle_user_per_hour' => 100,
            'app.checkout_throttle_ip_per_hour' => 100,
            'app.checkout_throttle_ip_per_day' => 2,
        ]);
        $service = app(CheckoutRiskService::class);
        $request = $this->requestFromIp('198.51.100.43');
        $users = User::factory()->count(3)->create();

        $service->assertCheckoutQuotaAllowed($request, $users[0]);
        $service->assertCheckoutQuotaAllowed($request, $users[1]);
        $this->assertQuotaDenied(fn () => $service->assertCheckoutQuotaAllowed($request, $users[2]));
    }

    public function test_untrusted_forwarded_for_does_not_change_checkout_ip_or_quota_keys(): void
    {
        config([
            'app.checkout_throttle_user_per_hour' => 100,
            'app.checkout_throttle_ip_per_hour' => 100,
            'app.checkout_throttle_ip_per_day' => 100,
            'app.stripe.customers_enabled' => false,
            'services.turnstile.site_key' => 'site-key',
            'services.turnstile.secret' => 'server-secret',
            'app.turnstile_user_threshold_per_hour' => 100,
            'app.turnstile_ip_threshold_per_hour' => 100,
            'app.turnstile_failure_user_threshold_per_hour' => 100,
            'app.turnstile_failure_ip_threshold_per_hour' => 100,
        ]);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);

        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Web license',
            'base_price' => 1000,
            'flatrate_tier' => 'web',
            'brand' => 'rp',
        ]);

        $remoteIp = '198.51.100.44';
        $forwardedIp = '203.0.113.44';
        $this->mockStripePaymentIntentSuccess();

        try {
            $response = $this
                ->withServerVariables(['REMOTE_ADDR' => $remoteIp])
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'X-Forwarded-For' => $forwardedIp,
                ])
                ->postJson('/api/orders/checkout', [
                    'items' => [[
                        'photoId' => $photo->id,
                        'tier' => 'web',
                        'useCaseId' => $useCase->id,
                    ]],
                    'billing_name' => 'Test',
                    'billing_street' => 'Street 1',
                    'billing_zip' => '1010',
                    'billing_city' => 'Vienna',
                    'withdrawal_waived' => true,
                ]);

            $response->assertOk();
            $this->assertSame($forwardedIp, $response->baseRequest?->header('X-Forwarded-For'));
            $this->assertSame($remoteIp, $response->baseRequest?->ip());
            $order = Order::findOrFail($response->json('order_id'));

            $this->assertSame($remoteIp, $order->ip_address);
            $this->assertSame(1, RateLimiter::attempts(CheckoutKey::ip($remoteIp, 'checkout-quota-hour')));
            $this->assertSame(1, RateLimiter::attempts(CheckoutKey::ip($remoteIp, 'checkout-quota-day')));
            $this->assertSame(1, RateLimiter::attempts(CheckoutKey::ip($remoteIp, 'checkout-risk-attempt')));
            $this->assertSame(0, RateLimiter::attempts(CheckoutKey::ip($forwardedIp, 'checkout-quota-hour')));
            $this->assertSame(0, RateLimiter::attempts(CheckoutKey::ip($forwardedIp, 'checkout-quota-day')));
            $this->assertSame(0, RateLimiter::attempts(CheckoutKey::ip($forwardedIp, 'checkout-risk-attempt')));
        } finally {
            $this->resetStripeHttpClient();
        }
    }

    public function test_quote_invoice_and_free_paths_do_not_consume_checkout_quota(): void
    {
        config(['app.checkout_throttle_user_per_hour' => 1]);
        Mail::fake();
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $totalCents = 1000;
        $discountCents = 0;

        $strategy = $this->createMock(PricingStrategy::class);
        $strategy->method('supportsCoupons')->willReturn(false);
        $strategy->method('calculateCart')->willReturnCallback(function () use (&$totalCents, &$discountCents, $photo): array {
            return [
                'items' => [[
                    'itemId' => $photo->id,
                    'priceCents' => $totalCents,
                    'tier' => 'web',
                    'useCaseName' => 'Test',
                    'modifierNames' => [],
                ]],
                'totalCents' => $totalCents,
                'discountCents' => $discountCents,
                'couponId' => null,
                'couponType' => $discountCents > 0 ? 'fixed' : null,
            ];
        });
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $risk = $this->createMock(CheckoutRiskService::class);
        $risk->expects($this->never())->method('assertCheckoutQuotaAllowed');
        $risk->expects($this->never())->method('assertImmediateStripeAllowed');
        $service = new CheckoutService($strategy, $stripe, null, null, $risk);

        $base = [
            'billing_name' => 'Test',
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'withdrawal_waived' => true,
        ];
        $quote = Request::create('/', 'POST', $base + [
            'items' => [['photoId' => $photo->id, 'isQuote' => true]],
        ]);
        $this->assertSame(200, $service->processCheckout($quote, $user, 'stripe')->status());

        $invoice = Request::create('/', 'POST', $base + [
            'items' => [['photoId' => $photo->id, 'tier' => 'web']],
        ]);
        $this->assertSame(200, $service->processCheckout($invoice, $user, 'invoice')->status());

        $totalCents = 0;
        $discountCents = 1000;
        $free = Request::create('/', 'POST', $base + [
            'items' => [['photoId' => $photo->id, 'tier' => 'web']],
        ]);
        $this->assertSame(200, $service->processCheckout($free, $user, 'stripe')->status());
    }

    private function requestFromIp(string $ip): Request
    {
        return Request::create('/api/orders/checkout', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    private function assertQuotaDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected checkout quota rejection.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(429, $exception->getResponse()->getStatusCode());
            $this->assertNotEmpty($exception->getResponse()->headers->get('Retry-After'));
        }
    }
}
