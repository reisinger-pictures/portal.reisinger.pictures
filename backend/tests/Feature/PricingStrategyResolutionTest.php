<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use App\Services\CheckoutService;
use App\Pricing\ScopeLicensingStrategy;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingStrategyResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'scope_licensing']
        );
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        parent::tearDown();
    }

    public function test_gallery_with_null_licensing_mode_falls_back_to_brand_default(): void
    {
        BrandRegistry::set(Brand::B2B);
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing']
        );

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);

        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);
    }

    public function test_gallery_with_null_licensing_mode_defaults_to_scope_licensing_when_setting_missing(): void
    {
        Setting::where('key', 'pricing_strategy')->delete();

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);

        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);
    }

    public function test_gallery_with_volume_licensing_override_returns_volume(): void
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);

        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);
    }

    public function test_gallery_with_scope_licensing_override_returns_scope(): void
    {
        BrandRegistry::set(Brand::B2B);
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'volume_licensing']
        );

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'scope_licensing',
        ]);

        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);
    }

    public function test_gallery_override_takes_precedence_over_brand_setting(): void
    {
        BrandRegistry::set(Brand::B2B);
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'scope_licensing']
        );

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);

        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);
    }

    public function test_effective_licensing_mode_is_appended_to_json_response(): void
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
        ]);

        $responseData = $gallery->toArray();
        $this->assertArrayHasKey('effective_licensing_mode', $responseData);
        $this->assertSame('volume_licensing', $responseData['effective_licensing_mode']);
    }
}
