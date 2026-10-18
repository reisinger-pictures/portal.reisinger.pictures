<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the publicly reachable license endpoint must not leak the
 * `effective_licensing_mode` (and therefore a volume preset) of a gallery
 * belonging to another brand.
 *
 * The buy flow now reads the minimized `/api/settings/license-pricing`; the
 * full `/api/settings/license-terms` payload is super-admin only and is checked
 * with a super-admin token alongside, so the brand guard is pinned on both
 * routes.
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

    private function superAdminToken(): string
    {
        // `null` is the explicit cross-brand Super-Admin actor
        // (`App\Enums\Brand` docblock): this test sets the brand context to
        // `srp`, so a brand-bound super admin would legitimately get 403
        // (Brand Isolation) instead of exercising the route under test.
        $user = User::factory()->create(['brand' => null]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($user);
    }

    public function test_license_pricing_ignores_foreign_brand_gallery_mode(): void
    {
        $this->setBrand('srp');

        $foreignGallery = Gallery::factory()->create([
            'brand' => 'rp',
            'licensing_mode' => 'volume_licensing',
        ]);

        $response = $this->getJson('/api/settings/license-pricing?gallery_id='.$foreignGallery->id);

        $response->assertOk();
        $response->assertJsonPath('pricing_strategy', 'scope_licensing');
        // The foreign preset is not leaked either.
        $response->assertJsonPath('volume_pricing', null);

        // The full super-admin route keeps the same brand guard.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->superAdminToken()])
            ->getJson('/api/settings/license-terms?gallery_id='.$foreignGallery->id)
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'scope_licensing')
            ->assertJsonPath('volume_pricing', null);
    }

    public function test_license_pricing_honors_same_brand_gallery_mode(): void
    {
        $this->setBrand('srp');

        $ownGallery = Gallery::factory()->create([
            'brand' => 'srp',
            'licensing_mode' => 'volume_licensing',
        ]);

        $response = $this->getJson('/api/settings/license-pricing?gallery_id='.$ownGallery->id);

        $response->assertOk();
        $response->assertJsonPath('pricing_strategy', 'volume_licensing');

        // The full super-admin route resolves the same gallery override.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->superAdminToken()])
            ->getJson('/api/settings/license-terms?gallery_id='.$ownGallery->id)
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'volume_licensing');
    }
}
