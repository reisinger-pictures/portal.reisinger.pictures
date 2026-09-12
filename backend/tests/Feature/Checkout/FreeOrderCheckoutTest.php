<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Mail\InvoiceMail;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PurchaseService;
use App\Services\StripePaymentService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * End-to-end regression for the "free order" decision:
 * a 100%-off coupon reduces the cart to exactly 0, which must be fulfilled
 * without a Stripe PaymentIntent and must grant download access.
 */
class FreeOrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    private function makeRequest(array $items, array $extra = []): Request
    {
        return Request::create('/', 'POST', array_merge([
            'items' => $items,
            'billing_name' => 'Tester',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1234',
            'billing_city' => 'Wien',
            'withdrawal_waived' => true,
        ], $extra));
    }

    public function test_full_percentage_coupon_creates_paid_download_eligible_order_without_stripe(): void
    {
        $coupon = Coupon::factory()->percentage(100)->create([
            'brand' => Brand::B2B->value,
            'code' => 'FREE100',
            'scope_type' => 'global',
            'active' => true,
        ]);

        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();

        // Real volume strategy + real coupon application (single-strategy path).
        $preset = app(VolumePresetService::class)->resolveDefaultForBrand(Brand::B2B);
        $strategy = new VolumeLicensingStrategy($preset, app(CouponService::class));

        $stripe = $this->createMock(StripePaymentService::class);
        $stripe->expects($this->never())->method('createPaymentIntent');

        Mail::fake();

        $service = new CheckoutService($strategy, $stripe, app(CouponService::class));

        $response = $service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'tier' => 'volume']], ['coupon_code' => 'FREE100']),
            $user,
            'stripe'
        );

        $this->assertSame(200, $response->status());

        $order = Order::first();
        $this->assertNotNull($order);
        $this->assertSame(0, $order->total_amount);
        $this->assertSame('paid', $order->status);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertNull($order->stripe_payment_intent_id);

        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count);

        Mail::assertQueued(InvoiceMail::class);

        // Download gate: settled status must make the order's media downloadable.
        $this->assertTrue(app(PurchaseService::class)->isOrderDownloadEligible($order));
    }
}
