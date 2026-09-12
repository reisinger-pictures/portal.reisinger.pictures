<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Coupon;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Product;
use App\Models\Setting;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_factory_defaults_to_the_default_brand(): void
    {
        $gallery = Gallery::factory()->create();

        $this->assertNotNull($gallery->brand);
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => BrandRegistry::currentId(),
        ]);
    }

    public function test_gallery_group_factory_defaults_to_the_default_brand(): void
    {
        $group = GalleryGroup::factory()->create();

        $this->assertNotNull($group->brand);
        $this->assertDatabaseHas('gallery_groups', [
            'id' => $group->id,
            'brand' => BrandRegistry::currentId(),
        ]);
    }

    public function test_product_factory_rows_are_visible_to_brand_scoped_queries(): void
    {
        $product = Product::factory()->create();

        $this->assertNotNull(
            Product::forCurrentBrand()->find($product->id)
        );
    }

    public function test_setting_factory_rows_are_visible_to_brand_scoped_queries(): void
    {
        $setting = Setting::factory()->create();

        $this->assertTrue(
            Setting::forCurrentBrand()->where('key', $setting->key)->exists()
        );
    }

    public function test_coupon_factory_scopes_accept_uuid_strings(): void
    {
        $gallery = Gallery::factory()->create();
        $group = GalleryGroup::factory()->create();

        $galleryCoupon = Coupon::factory()->scopedToGallery($gallery->id)->create(['brand' => Brand::B2B->value]);
        $groupCoupon = Coupon::factory()->scopedToMetaGallery($group->id)->create(['brand' => Brand::B2B->value]);

        $this->assertSame($gallery->id, $galleryCoupon->fresh()->scope_id);
        $this->assertSame($group->id, $groupCoupon->fresh()->scope_id);
    }
}
