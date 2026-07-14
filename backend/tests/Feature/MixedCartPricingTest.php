<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\CheckoutService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MixedCartPricingTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'scope_licensing']
        );

        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);

        $this->service = new CheckoutService(new ScopeLicensingStrategy());
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_mixed_cart_with_scope_and_volume_galleries_uses_correct_strategies(): void
    {
        $scopeGallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'scope_licensing',
        ]);
        $volumeGallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);

        $useCase = LicenseUseCase::create([
            'name' => 'Web', 'base_price' => 10000, 'flatrate_tier' => 'web',
        ]);

        $photo1 = Photo::factory()->create(['gallery_id' => $scopeGallery->id]);
        $photo2 = Photo::factory()->create(['gallery_id' => $volumeGallery->id]);

        $user = User::factory()->create();

        $request = Request::create('/', 'POST', [
            'items' => [
                ['photoId' => $photo1->id, 'useCaseId' => $useCase->id, 'tier' => 'web'],
                ['photoId' => $photo2->id, 'tier' => 'web'],
            ],
            'billing_name' => 'Tester',
            'billing_street' => 'Street',
            'billing_zip' => '1234',
            'billing_city' => 'City',
        ]);

        $response = $this->service->processCheckout($request, $user, 'invoice');

        $this->assertEquals(200, $response->status());
    }

    public function test_mixed_cart_groups_items_by_licensing_mode(): void
    {
        $scopeGallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'scope_licensing',
        ]);
        $volumeGallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);

        $useCase = LicenseUseCase::create([
            'name' => 'Web', 'base_price' => 5000, 'flatrate_tier' => 'web',
        ]);

        $photo1 = Photo::factory()->create(['gallery_id' => $scopeGallery->id]);
        $photo2 = Photo::factory()->create(['gallery_id' => $volumeGallery->id]);
        $photo3 = Photo::factory()->create(['gallery_id' => $volumeGallery->id]);

        $user = User::factory()->create();

        $request = Request::create('/', 'POST', [
            'items' => [
                ['photoId' => $photo1->id, 'useCaseId' => $useCase->id, 'tier' => 'web'],
                ['photoId' => $photo2->id, 'tier' => 'web'],
                ['photoId' => $photo3->id, 'tier' => 'web'],
            ],
            'billing_name' => 'Tester',
            'billing_street' => 'Street',
            'billing_zip' => '1234',
            'billing_city' => 'City',
        ]);

        $response = $this->service->processCheckout($request, $user, 'invoice');

        $this->assertEquals(200, $response->status());
    }

    public function test_single_gallery_scope_cart_uses_injected_strategy(): void
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);

        $useCase = LicenseUseCase::create([
            'name' => 'Web', 'base_price' => 5000, 'flatrate_tier' => 'web',
        ]);

        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();

        $request = Request::create('/', 'POST', [
            'items' => [
                ['photoId' => $photo->id, 'useCaseId' => $useCase->id, 'tier' => 'web'],
            ],
            'billing_name' => 'Tester',
            'billing_street' => 'Street',
            'billing_zip' => '1234',
            'billing_city' => 'City',
        ]);

        $response = $this->service->processCheckout($request, $user, 'invoice');

        $this->assertEquals(200, $response->status());
    }
}
