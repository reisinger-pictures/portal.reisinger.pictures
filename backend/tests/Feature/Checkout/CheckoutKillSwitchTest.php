<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutKillSwitch;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Tests\Support\FakesMollie;
use Tests\TestCase;

class CheckoutKillSwitchTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    private MockMollieClient $mollieClient;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        config(['checkout.enabled' => true]);
        Mail::fake();
        $this->configureMollie();
        $this->mollieClient = $this->fakeMollie(
            [CreatePaymentRequest::class => $this->mollieCreateResponder('open')],
        );
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_disabled_switch_blocks_positive_online_checkout_before_any_order_or_payment_creation(): void
    {
        config(['checkout.enabled' => false]);
        [$user, $photo, $useCase] = $this->checkoutData();

        $response = (new CheckoutService(new ScopeLicensingStrategy))->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-disabled-0001'),
            $user,
            'mollie',
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Der Checkout ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.', $response->getData(true)['error']);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_resuming_an_open_mollie_hosted_link_ignores_the_kill_switch(): void
    {
        $client = $this->fakeMollie(
            [CreatePaymentRequest::class => $this->mollieCreateResponder('open')],
            retainRequests: true,
        );
        [$user, $photo, $useCase] = $this->checkoutData();
        $key = 'kill-switch-resume-0001';
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $first = $service->processCheckout($this->request($photo, $useCase, $key), $user, 'mollie');
        $this->assertSame(200, $first->getStatusCode());
        $this->assertTrue($first->getData(true)['payment_pending']);
        $this->assertCount(1, $this->mollieRecordedIdempotencyKeys($client));

        $order = Order::query()->sole();
        config(['checkout.enabled' => false]);

        // A customer returning to a still-open hosted link must not be stopped
        // by the kill switch: the persisted attempt already exists, so `resume`
        // needs no new provider work and no admission budget.
        $resumed = $service->processCheckout($this->request($photo, $useCase, $key), $user, 'mollie');

        $this->assertSame(200, $resumed->getStatusCode());
        $this->assertTrue($resumed->getData(true)['payment_pending']);
        $this->assertSame((string) $order->id, $resumed->getData(true)['order_id']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, MolliePaymentAttempt::query()->count());
        $this->assertCount(1, $this->mollieRecordedIdempotencyKeys($client));
    }

    public function test_enabled_switch_allows_a_valid_positive_online_checkout(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();

        $response = (new CheckoutService(new ScopeLicensingStrategy))->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-enabled-0001'),
            $user,
            'mollie',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['payment_pending']);

        $order = Order::query()->sole();
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame(1, MolliePaymentAttempt::query()->where('order_id', $order->id)->count());
    }

    public function test_disabled_switch_does_not_block_invoice_or_quote_paths(): void
    {
        config(['checkout.enabled' => false]);
        [$user, $photo, $useCase] = $this->checkoutData();
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $invoice = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-invoice-0001'),
            $user,
            'invoice',
        );
        $quote = $service->processCheckout(
            $this->request($photo, $useCase, 'kill-switch-quote-0001', true),
            $user,
            'mollie',
        );

        $this->assertSame(200, $invoice->getStatusCode());
        $this->assertSame(200, $quote->getStatusCode());
        $this->assertDatabaseHas('orders', ['id' => $invoice->getData(true)['order_id'], 'status' => 'invoice_created']);
        $this->assertDatabaseHas('orders', ['id' => $quote->getData(true)['order_id'], 'status' => 'pending']);
    }

    public function test_non_online_invoice_checkout_captures_the_first_checkout_ip(): void
    {
        [$user, $photo, $useCase] = $this->checkoutData();

        $response = (new CheckoutService(new ScopeLicensingStrategy))->processCheckout(
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

    public function test_recent_account_with_a_full_discount_free_cart_skips_admission_and_payment_creation(): void
    {
        config(['checkout.new_account_hours' => 24]);
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
        $strategy = new VolumeLicensingStrategy(
            app(VolumePresetService::class)->ensureDefaultPresetForBrand(Brand::B2B),
            app(CouponService::class),
        );

        $response = (new CheckoutService($strategy))->processCheckout(
            $this->request($photo, $useCase, 'recent-free-cart-0001', false, 'FREE100'),
            $user,
            'mollie',
        );

        $this->assertSame(200, $response->getStatusCode());
        $order = Order::query()->sole();
        $this->assertSame('paid', $order->status);
        $this->assertSame(0, $order->molliePaymentAttempts()->count());
    }

    public function test_invalid_switch_configuration_fails_closed(): void
    {
        config(['checkout.enabled' => 'not-a-boolean']);
        Log::spy();
        $switch = app(CheckoutKillSwitch::class);

        $this->assertFalse($switch->enabled());
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'CHECKOUT_ENABLED')
                && ($context['configuration_type'] ?? null) === 'string');
    }

    public function test_the_shipped_configuration_keeps_an_unparseable_switch_flag_distinguishable(): void
    {
        // The contract says an invalid runtime configuration "fails closed and
        // is logged". That only holds if the shipped configuration does not
        // coerce an unparseable value into a silent `false`: FILTER_NULL_ON_
        // FAILURE keeps `null` distinguishable, and the switch treats every
        // non-boolean as invalid.
        putenv('CHECKOUT_ENABLED=flase');
        $_ENV['CHECKOUT_ENABLED'] = 'flase';
        $_SERVER['CHECKOUT_ENABLED'] = 'flase';

        try {
            $shipped = require base_path('config/checkout.php');

            $this->assertNull($shipped['enabled']);
        } finally {
            putenv('CHECKOUT_ENABLED');
            unset($_ENV['CHECKOUT_ENABLED'], $_SERVER['CHECKOUT_ENABLED']);
        }

        config(['checkout.enabled' => null]);
        Log::spy();

        $this->assertFalse(app(CheckoutKillSwitch::class)->enabled());
        Log::shouldHaveReceived('error')->once();
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
