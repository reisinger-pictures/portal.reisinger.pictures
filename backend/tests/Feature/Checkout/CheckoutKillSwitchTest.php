<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\StripeCheckoutKillSwitch;
use App\Services\StripePaymentService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        config([
            'app.stripe.checkout_enabled' => true,
            'app.stripe.customers_enabled' => false,
        ]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_disabled_switch_blocks_positive_stripe_checkout_before_any_order_or_pi_creation(): void
    {
        config(['app.stripe.checkout_enabled' => false]);
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $response = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-disabled-0001'),
            $user,
            'stripe',
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Der Checkout ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.', $response->getData(true)['error']);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_enabled_switch_allows_a_valid_positive_stripe_checkout(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->once())
            ->method('createPaymentIntent')
            ->willReturnCallback(function (int $amount, string $orderId, string $email, ?string $customerId, int $generation, int|string $userId, int|string|null $accountCreatedAt, ?string $key, ?string $fingerprint): array {
                $order = Order::query()->findOrFail($orderId);

                return [
                    'id' => 'pi_kill_switch_enabled',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_kill_switch_enabled_secret',
                    'amount' => $amount,
                    'amount_received' => null,
                    'currency' => 'eur',
                    'created' => time(),
                    'customer' => $customerId,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                        'portal_user_id' => (string) $userId,
                        'account_created_at' => (string) $accountCreatedAt,
                        'checkout_idempotency_key' => (string) $key,
                        'checkout_fingerprint' => (string) $fingerprint,
                        'generation' => (string) $generation,
                        'amount_cents' => (string) $amount,
                        'currency' => 'eur',
                    ],
                ];
            });
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $response = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-enabled-0001'),
            $user,
            'stripe',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('orders', [
            'id' => $response->getData(true)['order_id'],
            'status' => 'pending_payment',
            'stripe_payment_intent_id' => 'pi_kill_switch_enabled',
        ]);
    }

    public function test_disabled_switch_does_not_block_invoice_or_quote_paths(): void
    {
        config(['app.stripe.checkout_enabled' => false]);
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $invoice = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-invoice-0001'),
            $user,
            'invoice',
        );
        $quote = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-quote-0001', true),
            $user,
            'stripe',
        );

        $this->assertSame(200, $invoice->getStatusCode());
        $this->assertSame(200, $quote->getStatusCode());
        $this->assertDatabaseHas('orders', ['id' => $invoice->getData(true)['order_id'], 'status' => 'invoice_created']);
        $this->assertDatabaseHas('orders', ['id' => $quote->getData(true)['order_id'], 'status' => 'pending']);
    }

    public function test_non_stripe_invoice_checkout_captures_the_first_checkout_ip(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $service = new CheckoutService(new ScopeLicensingStrategy, $stripe);

        $response = $service->processCheckout(
            $this->request($photo, $useCase, 'invoice-ip-capture-0001', false, null, '198.51.100.81'),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('orders', [
            'id' => $response->getData(true)['order_id'],
            'ip_address' => '198.51.100.81',
        ]);
    }

    public function test_recent_account_with_a_full_discount_free_cart_skips_stripe_admission_and_pi_creation(): void
    {
        config([
            'app.stripe.checkout_enabled' => true,
            'app.stripe.checkout_new_account_hours' => 24,
        ]);
        $user = User::factory()->create(['created_at' => now()]);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Web Free Kill Switch',
            'base_price' => 4000,
            'flatrate_tier' => 'web',
            'brand' => Brand::B2B,
        ]);
        Coupon::factory()->percentage(100)->create([
            'brand' => Brand::B2B,
            'code' => 'FREE100',
            'active' => true,
        ]);
        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');
        $strategy = new VolumeLicensingStrategy(
            app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B),
            app(CouponService::class),
        );
        $service = new CheckoutService($strategy, $stripe);

        $response = $service->processCheckout(
            $this->request($photo, $useCase, 'recent-free-cart-0001', false, 'FREE100'),
            $user,
            'stripe',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('orders', [
            'id' => $response->getData(true)['order_id'],
            'status' => 'paid',
            'stripe_payment_intent_id' => null,
        ]);
    }

    public function test_invalid_switch_configuration_fails_closed(): void
    {
        config(['app.stripe.checkout_enabled' => 'not-a-boolean']);
        $switch = app(StripeCheckoutKillSwitch::class);

        $this->assertFalse($switch->enabled());
    }

    /**
     * @return array{0: User, 1: Photo, 2: LicenseUseCase}
     */
    private function checkoutData(): array
    {
        $user = User::factory()->create(['created_at' => now()->subDays(2)]);
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create([
            'name' => 'Web Kill Switch',
            'base_price' => 4000,
            'flatrate_tier' => 'web',
            'brand' => Brand::B2B,
        ]);

        return [$user, $photo, $useCase];
    }

    private function request(
        Photo $photo,
        LicenseUseCase $useCase,
        string $key,
        bool $quote = false,
        ?string $couponCode = null,
        ?string $ip = null,
    ): Request {
        $payload = [
            'items' => [[
                'photoId' => $photo->id,
                'tier' => 'web',
                'useCaseId' => $useCase->id,
                'isQuote' => $quote,
            ]],
            'billing_name' => 'Kill Switch User',
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'withdrawal_waived' => true,
        ];
        if ($couponCode !== null) {
            $payload['coupon_code'] = $couponCode;
        }

        $server = ['HTTP_IDEMPOTENCY_KEY' => $key];
        if ($ip !== null) {
            $server['REMOTE_ADDR'] = $ip;
        }

        return Request::create('/', 'POST', $payload, [], [], $server);
    }
}
