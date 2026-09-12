<?php

namespace Tests\Feature;

use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the public license-terms endpoint must not leak the
 * `effective_licensing_mode` (and therefore a volume preset) of a gallery
 * belonging to another brand.
 */
class BrandScopingLicenseTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The brand context is set explicitly per test; the middleware would
        // otherwise derive it from the test host (always B2B/rp).
        $this->withoutMiddleware(BrandContextMiddleware::class);
    }

    private function setBrand(string $id): void
    {
        BrandRegistry::set(new BrandConfig(
            id: $id,
            name: $id,
            theme: 'rp',
            portalName: $id,
            impressumUrl: null,
            logoPath: null,
        ));
    }

    public function test_license_terms_ignores_foreign_brand_gallery_mode(): void
    {
        $this->setBrand('srp');

        $foreignGallery = Gallery::factory()->create([
            'brand' => 'rp',
            'licensing_mode' => 'volume_licensing',
        ]);

        $response = $this->getJson('/api/settings/license-terms?gallery_id='.$foreignGallery->id);

        $response->assertOk();
        $response->assertJsonPath('pricing_strategy', 'scope_licensing');
    }

    public function test_license_terms_honors_same_brand_gallery_mode(): void
    {
        $this->setBrand('srp');

        $ownGallery = Gallery::factory()->create([
            'brand' => 'srp',
            'licensing_mode' => 'volume_licensing',
        ]);

        $response = $this->getJson('/api/settings/license-terms?gallery_id='.$ownGallery->id);

        $response->assertOk();
        $response->assertJsonPath('pricing_strategy', 'volume_licensing');
    }
}
