<?php

namespace Tests\Feature\Checkout;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\LicenseUseCase;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Models\VolumePreset;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PurchaseService;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Regression coverage for the checkout pricing boundary (CR-PAY-002/003/005).
 */
class PaymentPricingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Mail::fake();
        Storage::fake('photos');
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_explicit_scope_gallery_does_not_use_injected_volume_strategy(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $gallery = $this->createGallery(['licensing_mode' => 'scope_licensing']);
        $photo = $this->createPhoto($gallery);
        $useCase = LicenseUseCase::factory()->create([
            'brand' => Brand::B2B->value,
            'base_price' => 7000,
            'flatrate_tier' => 'original',
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);
        $coupon = Coupon::factory()->fixed(5)->create([
            'brand' => Brand::B2B->value,
            'code' => 'SCOPE-IGNORED',
            'active' => true,
        ]);

        $volumeStrategy = new VolumeLicensingStrategy(
            $this->createVolumePreset(1100, 'Wrong injected strategy'),
            app(CouponService::class),
        );
        $service = new CheckoutService($volumeStrategy);

        $response = $service->processCheckout(
            $this->makeRequest([[
                'photoId' => $photo->id,
                'useCaseId' => $useCase->id,
                'tier' => 'original',
            ]], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        $this->assertSame(7000, $order->total_amount);
        $this->assertNull($order->coupon_id);
        $this->assertSame(0, $coupon->fresh()->used_count);

        $snapshot = InvoiceSnapshot::sole();
        $this->assertSame('original', $snapshot->customer_details['items'][0]['tier']);
        $this->assertSame($useCase->name, $snapshot->customer_details['items'][0]['useCaseName']);
    }

    public function test_volume_checkout_persists_original_tier_and_allows_original_order_zip(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $gallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = $this->createPhoto($gallery);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([['photoId' => $photo->id, 'tier' => 'web']]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        $snapshot = InvoiceSnapshot::sole();
        $this->assertSame('original', $snapshot->customer_details['items'][0]['tier']);
        $this->assertTrue(app(PurchaseService::class)->hasPurchasedPhoto($user, $photo->id, 'original'));

        $fixture = base_path('tests/Fixtures/sample.jpg');
        $this->assertFileExists($fixture);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents($fixture),
        );

        $token = auth('api')->login($user);
        $zipResponse = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/orders/'.$order->id.'/download-zip');
        $zipResponse->assertOk();

        $zipContent = $zipResponse->streamedContent();
        $this->assertNotSame('', $zipContent);

        $zipPath = tempnam(sys_get_temp_dir(), 'payment-pricing-');
        $this->assertNotFalse($zipPath);
        file_put_contents($zipPath, $zipContent);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertNotFalse($zip->locateName($photo->id.'_ORIGINAL.jpg'));
        } finally {
            $zip->close();
            @unlink($zipPath);
        }
    }

    public function test_fixed_coupon_is_applied_once_across_multiple_volume_groups(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $firstGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(3000, 'Group A')->id,
        ]);
        $secondGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(4000, 'Group B')->id,
        ]);
        $firstPhoto = $this->createPhoto($firstGallery);
        $secondPhoto = $this->createPhoto($secondGallery);
        $coupon = Coupon::factory()->fixed(5)->create([
            'brand' => Brand::B2B->value,
            'code' => 'ONCE-FIXED',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        $this->assertSame(6500, $order->total_amount);
        $this->assertSame(500, $order->coupon_discount_cents);
        $this->assertSame(1, $coupon->fresh()->used_count);

        $discountLines = array_values(array_filter(
            InvoiceSnapshot::sole()->customer_details['items'],
            static fn (array $item): bool => ($item['type'] ?? null) === 'discount_coupon',
        ));
        $this->assertCount(1, $discountLines);
        $this->assertSame(-500, $discountLines[0]['row_total']);
    }

    public function test_percentage_max_items_coupon_is_applied_once_across_volume_groups(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $firstGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(3000, 'Percentage A')->id,
        ]);
        $secondGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(4000, 'Percentage B')->id,
        ]);
        $firstPhoto = $this->createPhoto($firstGallery);
        $secondPhoto = $this->createPhoto($secondGallery);
        $coupon = Coupon::factory()->percentageWithMaxItems(50, 1)->create([
            'brand' => Brand::B2B->value,
            'code' => 'ONCE-PERCENTAGE',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        // Only the cheapest item across the whole volume cart is discounted:
        // 50% of 3000, not 50% once per pricing group.
        $this->assertSame(5500, $order->total_amount);
        $this->assertSame(1500, $order->coupon_discount_cents);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_percentage_max_items_uses_effective_price_after_volume_tier(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        [$firstPhoto, $secondPhoto] = $this->createTwoItemTieredVolumePhotos('Effective percentage');
        $coupon = Coupon::factory()->percentageWithMaxItems(50, 1)->create([
            'brand' => Brand::B2B->value,
            'code' => 'EFFECTIVE-PERCENT',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        // Volume tier: 2 × 500 effective = 1000. The max-items coupon uses
        // 50% of the effective cheapest item (500), not the base 1000.
        $this->assertSame(750, $order->total_amount);
        $this->assertSame(250, $order->coupon_discount_cents);
        $this->assertSame(1, $coupon->fresh()->used_count);

        $items = InvoiceSnapshot::sole()->customer_details['items'];
        $baseLines = array_values(array_filter(
            $items,
            static fn (array $item): bool => isset($item['photoId']),
        ));
        $this->assertCount(2, $baseLines);
        $this->assertSame([1000, 1000], array_column($baseLines, 'price'));

        $tierLines = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['type'] ?? null) === 'discount_fixed',
        ));
        $this->assertSame(-1000, array_sum(array_column($tierLines, 'row_total')));

        $couponLines = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['type'] ?? null) === 'discount_coupon',
        ));
        $this->assertCount(1, $couponLines);
        $this->assertSame(-250, $couponLines[0]['row_total']);
        $this->assertSame(750, $this->invoiceItemsTotal($items));
    }

    public function test_direct_volume_coupon_uses_effective_qualifying_prices(): void
    {
        $preset = $this->createTieredVolumePreset('Direct effective percentage', [
            ['min_quantity' => 0, 'price_cents' => 1000],
            ['min_quantity' => 2, 'price_cents' => 500],
        ]);
        $gallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $preset->id,
        ]);
        $firstPhoto = $this->createPhoto($gallery);
        $secondPhoto = $this->createPhoto($gallery);
        $coupon = Coupon::factory()->percentageWithMaxItems(50, 1)->create([
            'brand' => Brand::B2B->value,
            'code' => 'DIRECT-EFFECTIVE',
            'active' => true,
        ]);
        $user = User::factory()->create(['brand' => Brand::B2B->value]);

        $strategy = new VolumeLicensingStrategy($preset, app(CouponService::class));
        $result = $strategy->calculateCart([
            ['id' => $firstPhoto->id, 'is_quote' => false],
            ['id' => $secondPhoto->id, 'is_quote' => false],
        ], $user, $coupon->code);

        $this->assertSame(750, $result['totalCents']);
        $this->assertSame(250, $result['discountCents']);
        $this->assertSame([1000, 1000], array_column($result['items'], 'priceCents'));
        $this->assertSame([500, 500], array_column($result['coupon_items'], 'priceCents'));
        $this->assertSame(-1000, array_sum(array_column($result['tier_breakdown'], 'row_total')));
    }

    public function test_percentage_without_max_items_uses_effective_subtotal(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        [$firstPhoto, $secondPhoto] = $this->createTwoItemTieredVolumePhotos('Effective percentage subtotal');
        $coupon = Coupon::factory()->percentage(10)->create([
            'brand' => Brand::B2B->value,
            'code' => 'EFFECTIVE-SUBTOTAL',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        // 10% of the effective 2 × 500 subtotal, not 10% of the 2 × 1000 base.
        $this->assertSame(900, $order->total_amount);
        $this->assertSame(100, $order->coupon_discount_cents);
    }

    public function test_photo_package_uses_effective_price_after_volume_tier(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        [$firstPhoto, $secondPhoto] = $this->createTwoItemTieredVolumePhotos('Effective package');
        $coupon = Coupon::factory()->photoPackage(1, 200)->create([
            'brand' => Brand::B2B->value,
            'code' => 'EFFECTIVE-PACKAGE',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $order = Order::sole();
        // The package covers one effective 500-cent item for 200 cents; the
        // base 1000-cent representation would incorrectly produce a 200-cent
        // order instead.
        $this->assertSame(700, $order->total_amount);
        $this->assertSame(300, $order->coupon_discount_cents);
    }

    public function test_scoped_coupon_matches_a_later_volume_group_item(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $firstGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(3000, 'Scoped A')->id,
        ]);
        $secondGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(4000, 'Scoped B')->id,
        ]);
        $firstPhoto = $this->createPhoto($firstGallery);
        $secondPhoto = $this->createPhoto($secondGallery);
        $coupon = Coupon::factory()->fixed(5)->scopedToGallery($secondGallery->id)->create([
            'brand' => Brand::B2B->value,
            'code' => 'SECOND-GALLERY',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $firstPhoto->id, 'tier' => 'web'],
                ['photoId' => $secondPhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(6500, Order::sole()->total_amount);
    }

    public function test_volume_strategy_scoped_coupon_checks_all_items(): void
    {
        $preset = $this->createVolumePreset(1000, 'Direct scoped strategy');
        $firstGallery = $this->createGallery(['licensing_mode' => 'volume_licensing']);
        $secondGallery = $this->createGallery(['licensing_mode' => 'volume_licensing']);
        $firstPhoto = $this->createPhoto($firstGallery);
        $secondPhoto = $this->createPhoto($secondGallery);
        $coupon = Coupon::factory()->fixed(5)->scopedToGallery($secondGallery->id)->create([
            'brand' => Brand::B2B->value,
            'code' => 'DIRECT-SECOND',
            'active' => true,
        ]);
        $user = User::factory()->create(['brand' => Brand::B2B->value]);

        $strategy = new VolumeLicensingStrategy($preset, app(CouponService::class));
        $result = $strategy->calculateCart([
            ['id' => $firstPhoto->id, 'is_quote' => false],
            ['id' => $secondPhoto->id, 'is_quote' => false],
        ], $user, $coupon->code);

        $this->assertSame(1500, $result['totalCents']);
        $this->assertSame(500, $result['discountCents']);
    }

    public function test_mixed_scope_and_volume_cart_discounts_only_the_volume_subtotal_once(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing'],
        );

        $scopeGallery = $this->createGallery(['licensing_mode' => 'scope_licensing']);
        $firstVolumeGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(3000, 'Mixed A')->id,
        ]);
        $secondVolumeGallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $this->createVolumePreset(4000, 'Mixed B')->id,
        ]);
        $scopePhoto = $this->createPhoto($scopeGallery);
        $firstVolumePhoto = $this->createPhoto($firstVolumeGallery);
        $secondVolumePhoto = $this->createPhoto($secondVolumeGallery);
        $useCase = LicenseUseCase::factory()->create([
            'brand' => Brand::B2B->value,
            'base_price' => 1000,
            'flatrate_tier' => 'original',
        ]);
        $coupon = Coupon::factory()->fixed(5)->create([
            'brand' => Brand::B2B->value,
            'code' => 'MIXED-ONCE',
            'active' => true,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B->value,
            'flatrate_level' => 'none',
        ]);

        $service = new CheckoutService(new ScopeLicensingStrategy);
        $response = $service->processCheckout(
            $this->makeRequest([
                ['photoId' => $scopePhoto->id, 'useCaseId' => $useCase->id, 'tier' => 'original'],
                ['photoId' => $firstVolumePhoto->id, 'tier' => 'web'],
                ['photoId' => $secondVolumePhoto->id, 'tier' => 'web'],
            ], ['coupon_code' => $coupon->code]),
            $user,
            'invoice',
        );

        $this->assertSame(200, $response->status());
        // Scope 1000 + volume subtotal 7000 - one fixed 500 discount.
        $this->assertSame(7500, Order::sole()->total_amount);
        $this->assertSame(500, Order::sole()->coupon_discount_cents);
    }

    private function makeRequest(array $items, array $extra = []): Request
    {
        return Request::create('/', 'POST', array_merge([
            'items' => $items,
            'billing_name' => 'Regression Tester',
            'billing_company' => null,
            'billing_street' => 'Test Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'withdrawal_waived' => true,
        ], $extra));
    }

    private function createGallery(array $attributes = []): Gallery
    {
        return Gallery::withoutSyncingToSearch(
            fn (): Gallery => Gallery::factory()->create(array_merge([
                'is_public' => true,
            ], $attributes)),
        );
    }

    private function createPhoto(Gallery $gallery): Photo
    {
        return Photo::withoutSyncingToSearch(
            fn (): Photo => Photo::factory()->create(['gallery_id' => $gallery->id]),
        );
    }

    private function createTwoItemTieredVolumePhotos(string $name): array
    {
        $preset = $this->createTieredVolumePreset($name, [
            ['min_quantity' => 0, 'price_cents' => 1000],
            ['min_quantity' => 2, 'price_cents' => 500],
        ]);
        $gallery = $this->createGallery([
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $preset->id,
        ]);

        return [$this->createPhoto($gallery), $this->createPhoto($gallery)];
    }

    private function createTieredVolumePreset(string $name, array $tiers): VolumePreset
    {
        return app(VolumePresetService::class)->create($name, $tiers);
    }

    private function createVolumePreset(int $priceCents, string $name): VolumePreset
    {
        return $this->createTieredVolumePreset($name, [[
            'min_quantity' => 0,
            'price_cents' => $priceCents,
        ]]);
    }

    private function invoiceItemsTotal(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) ($item['row_total'] ?? $item['price'] ?? 0);
        }

        return $total;
    }
}
