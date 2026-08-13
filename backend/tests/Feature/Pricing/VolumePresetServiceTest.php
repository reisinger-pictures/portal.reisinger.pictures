<?php

namespace Tests\Feature\Pricing;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\Setting;
use App\Models\VolumePreset;
use App\Models\VolumePresetTier;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolumePresetServiceTest extends TestCase
{
    use RefreshDatabase;

    private VolumePresetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        $this->service = app(VolumePresetService::class);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_ensure_default_preset_creates_default_tiers(): void
    {
        $preset = $this->service->ensureDefaultPresetForBrand(Brand::B2B);

        $this->assertTrue($preset->is_default);
        $this->assertSame('Standard', $preset->name);
        $this->assertCount(3, $preset->tiers);
        $this->assertSame(3000, $preset->tiers[0]->price_cents);
        $this->assertSame(10, $preset->tiers[1]->min_quantity);
        $this->assertSame(20, $preset->tiers[2]->min_quantity);
    }

    public function test_ensure_default_preset_is_idempotent(): void
    {
        $first = $this->service->ensureDefaultPresetForBrand(Brand::B2B);
        $second = $this->service->ensureDefaultPresetForBrand(Brand::B2B);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VolumePreset::where('brand', 'rp')->count());
    }

    public function test_legacy_srp_settings_are_migrated_into_default_preset(): void
    {
        Setting::updateOrCreate(['key' => 'srp_price_per_image_tier1', 'brand' => 'rp'], ['value' => '4000']);
        Setting::updateOrCreate(['key' => 'srp_price_per_image_tier2', 'brand' => 'rp'], ['value' => '3500']);
        Setting::updateOrCreate(['key' => 'srp_price_per_image_tier3', 'brand' => 'rp'], ['value' => '3000']);
        Setting::updateOrCreate(['key' => 'srp_tier_threshold1', 'brand' => 'rp'], ['value' => '5']);
        Setting::updateOrCreate(['key' => 'srp_tier_threshold2', 'brand' => 'rp'], ['value' => '15']);

        $preset = $this->service->ensureDefaultPresetForBrand(Brand::B2B);

        $this->assertSame(
            [[0, 4000], [5, 3500], [15, 3000]],
            $preset->tiers->map(fn ($t) => [$t->min_quantity, $t->price_cents])->values()->toArray()
        );
    }

    public function test_only_one_default_preset_per_brand(): void
    {
        $this->service->create('A', [['min_quantity' => 0, 'price_cents' => 1000]]);
        $b = $this->service->create('B', [['min_quantity' => 0, 'price_cents' => 2000]]);
        $this->service->setDefault($b);

        $this->assertSame(1, VolumePreset::where('brand', 'rp')->where('is_default', true)->count());
        $this->assertTrue(VolumePreset::where('brand', 'rp')->where('name', 'B')->first()->is_default);
    }

    public function test_first_preset_created_becomes_default(): void
    {
        $preset = $this->service->create('Erstes', [['min_quantity' => 0, 'price_cents' => 1000]]);

        $this->assertTrue($preset->is_default);
    }

    public function test_update_replaces_tiers_preserving_sort_order(): void
    {
        $preset = $this->service->create('Test', [['min_quantity' => 0, 'price_cents' => 1000], ['min_quantity' => 10, 'price_cents' => 800]]);

        $this->service->update($preset, 'Geändert', [
            ['min_quantity' => 5, 'price_cents' => 900],
            ['min_quantity' => 0, 'price_cents' => 1200],
            ['min_quantity' => 20, 'price_cents' => 600],
        ]);

        $preset->refresh();
        $this->assertSame('Geändert', $preset->name);
        $this->assertCount(3, $preset->tiers);
        $this->assertSame([0, 5, 20], $preset->tiers->pluck('min_quantity')->values()->toArray());
        $this->assertSame([1200, 900, 600], $preset->tiers->pluck('price_cents')->values()->toArray());
    }

    public function test_delete_reassigns_galleries_to_default(): void
    {
        $default = $this->service->ensureDefaultPresetForBrand(Brand::B2B);
        $custom = $this->service->create('Custom', [['min_quantity' => 0, 'price_cents' => 5000]]);

        $gallery = Gallery::factory()->create(['volume_preset_id' => $custom->id]);

        $this->service->delete($custom);

        $this->assertNull($gallery->fresh()->volume_preset_id);
        $this->assertSame(1, VolumePreset::where('brand', 'rp')->count());
    }

    public function test_delete_default_preset_is_rejected(): void
    {
        $default = $this->service->ensureDefaultPresetForBrand(Brand::B2B);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->delete($default);
    }

    public function test_resolve_for_gallery_prefers_gallery_preset(): void
    {
        $default = $this->service->ensureDefaultPresetForBrand(Brand::B2B);
        $custom = $this->service->create('Custom', [['min_quantity' => 0, 'price_cents' => 5000]]);
        $gallery = Gallery::factory()->create(['volume_preset_id' => $custom->id]);

        $this->assertSame($custom->id, $this->service->resolveForGallery($gallery)->id);
    }

    public function test_resolve_for_gallery_falls_back_to_brand_default(): void
    {
        $default = $this->service->ensureDefaultPresetForBrand(Brand::B2B);
        $gallery = Gallery::factory()->create(['volume_preset_id' => null]);

        $this->assertSame($default->id, $this->service->resolveForGallery($gallery)->id);
    }
}
