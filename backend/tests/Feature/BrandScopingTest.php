<?php
namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandScopingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T-09 P3: a brand-bound user (brand != null) may only see galleries of their own brand.
     */
    public function test_brand_bound_user_only_sees_own_brand_galleries(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $b2bGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $user->galleries()->attach($b2bGallery->id);

        $allowed = $user->getAllowedGalleryIds();

        $this->assertContains($b2bGallery->id, $allowed);
    }

    /**
     * T-09 P3: a cross-brand user (brand = null, e.g. Super-Admin) sees galleries of all brands.
     */
    public function test_cross_brand_user_sees_all_brand_galleries(): void
    {
        $user = User::factory()->create(['brand' => null]);

        $b2bGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $user->galleries()->attach($b2bGallery->id);

        $allowed = $user->getAllowedGalleryIds();

        $this->assertContains($b2bGallery->id, $allowed);
    }

    /**
     * Galleries without an explicit brand (NULL) are no longer visible — the OR-NULL clause
     * was removed. The V020 migration backfills all existing null-brand galleries to 'rp'.
     */
    public function test_legacy_null_brand_gallery_is_rejected_for_brand_bound_user(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $legacyGallery = Gallery::factory()->create(['brand' => null]);
        $user->galleries()->attach($legacyGallery->id);

        $this->assertNotContains($legacyGallery->id, $user->getAllowedGalleryIds());
    }

    /**
     * AuthController::me() exposes the brand + cross-brand flag.
     */
    public function test_me_endpoint_exposes_brand_and_cross_brand_flag(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');
        $response->assertOk();
        $response->assertJsonPath('brand', Brand::B2B->value);
        $response->assertJsonPath('is_cross_brand', false);
    }

    public function test_me_endpoint_reports_cross_brand_for_null_brand_user(): void
    {
        $user = User::factory()->create(['brand' => null]);

        $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');
        $response->assertOk();
        $response->assertJsonPath('brand', null);
        $response->assertJsonPath('is_cross_brand', true);
    }
}
