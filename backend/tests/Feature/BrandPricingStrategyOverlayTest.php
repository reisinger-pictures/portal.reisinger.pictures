<?php

namespace Tests\Feature;

use App\Contracts\PricingStrategy;
use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Pricing\VolumeLicensingStrategy;
use App\Services\BrandSettingsService;
use App\Services\PricingStrategyResolver;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand-scoped pricing strategy as a per-brand overlay (F3 pattern).
 *
 * The overlay lives in the existing `settings` table under
 * `brand_config.pricing_strategy` and is written through the brand-settings
 * endpoint. It is the strongest source: overlay → settings row → B2B fallback
 * → default. Every consumer (gallery mode, public pricing endpoint, runtime
 * strategy binding) reads the same resolver, so they can never disagree.
 */
class BrandPricingStrategyOverlayTest extends TestCase
{
    use RefreshDatabase;

    private BrandSettingsService $brandSettings;

    protected function setUp(): void
    {
        parent::setUp();

        // BrandRegistry memoizes the merged brand configs in a static property
        // that RefreshDatabase does not reset between tests.
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);

        $this->brandSettings = app(BrandSettingsService::class);
    }

    protected function tearDown(): void
    {
        BrandRegistry::reset();
        BrandRegistry::clearCache();

        parent::tearDown();
    }

    private function setStrategySetting(string $value, string $brand = 'rp'): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => $brand], ['value' => $value]);
    }

    private function superAdminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($user);
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return auth('api')->login($user);
    }

    /**
     * @return array{Authorization: string}
     */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }

    public function test_the_overlay_wins_over_the_pricing_strategy_settings_row(): void
    {
        $this->setStrategySetting('scope_licensing');
        $this->brandSettings->apply('rp', ['pricing_strategy' => 'volume_licensing']);

        // 1. The gallery mode.
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);

        // 2. The public pricing endpoint.
        $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'volume_licensing');

        // 3. The injected runtime strategy.
        $this->assertInstanceOf(VolumeLicensingStrategy::class, app(PricingStrategy::class));
    }

    /**
     * The overlay must be read fresh, not through the process-local memo.
     *
     * `BrandRegistry` keeps the merged brand configs in a static property
     * that only `clearCache()` empties, and the HTTP path clears it in the
     * *writing* process only. A worker that had already read the brand config
     * would therefore keep resolving the pre-write `pricingStrategy = null`
     * and fall back to the settings row — the same cart object priced
     * differently depending on which worker served the request.
     */
    public function test_the_overlay_is_read_fresh_even_when_the_brand_config_memo_predates_the_write(): void
    {
        // Fill the memo *before* the write: the merged config is built now and
        // carries no pricing strategy yet.
        $this->assertNull(BrandRegistry::configForBrand('rp')?->pricingStrategy);

        // Write the overlay without clearing the memo. `audit=false` is the
        // path the management controller uses, and it leaves the cache alone.
        $this->brandSettings->apply('rp', ['pricing_strategy' => 'volume_licensing'], false);

        // The memo still holds the pre-write value …
        $this->assertNull(BrandRegistry::configForBrand('rp')?->pricingStrategy);

        // … but the resolution reads the overlay row directly and therefore
        // sees the new mode in every worker.
        $resolver = app(PricingStrategyResolver::class);
        $this->assertSame('volume_licensing', $resolver->resolve('rp'));
        $this->assertSame(PricingStrategyResolver::SOURCE_OVERLAY, $resolver->source('rp'));
    }

    public function test_without_an_overlay_the_settings_row_governs(): void
    {
        $this->setStrategySetting('volume_licensing');

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);

        $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'volume_licensing');

        $this->assertInstanceOf(VolumeLicensingStrategy::class, app(PricingStrategy::class));
    }

    public function test_without_an_overlay_and_without_a_settings_row_scope_licensing_applies(): void
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);

        $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'scope_licensing');

        $this->assertInstanceOf(ScopeLicensingStrategy::class, app(PricingStrategy::class));
    }

    public function test_an_unknown_settings_row_value_is_ignored(): void
    {
        $this->setStrategySetting('bogus');

        $resolver = app(PricingStrategyResolver::class);
        $this->assertSame('scope_licensing', $resolver->resolve('rp'));
        $this->assertSame(PricingStrategyResolver::SOURCE_DEFAULT, $resolver->source('rp'));

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);

        $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'scope_licensing');

        $this->assertInstanceOf(ScopeLicensingStrategy::class, app(PricingStrategy::class));
    }

    public function test_an_unknown_overlay_value_is_ignored_and_the_settings_row_applies(): void
    {
        $this->setStrategySetting('volume_licensing');
        $this->brandSettings->apply('rp', ['pricing_strategy' => 'bogus']);

        $resolver = app(PricingStrategyResolver::class);
        $this->assertSame('volume_licensing', $resolver->resolve('rp'));
        $this->assertSame(PricingStrategyResolver::SOURCE_SETTING, $resolver->source('rp'));

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);
    }

    public function test_an_unknown_overlay_value_without_a_settings_row_falls_back_to_the_default(): void
    {
        $this->brandSettings->apply('rp', ['pricing_strategy' => 'bogus']);

        $resolver = app(PricingStrategyResolver::class);
        $this->assertSame('scope_licensing', $resolver->resolve('rp'));
        $this->assertSame(PricingStrategyResolver::SOURCE_DEFAULT, $resolver->source('rp'));
    }

    public function test_a_brand_without_its_own_row_falls_back_to_the_rp_row(): void
    {
        $this->setStrategySetting('volume_licensing', 'rp');

        $resolver = app(PricingStrategyResolver::class);

        // A brand the config does not know still inherits the canonical B2B row.
        $this->assertSame('volume_licensing', $resolver->resolve('other'));
        $this->assertSame(PricingStrategyResolver::SOURCE_SETTING, $resolver->source('other'));

        // The brand's own row wins over the B2B fallback.
        $this->setStrategySetting('scope_licensing', 'other');
        $this->assertSame('scope_licensing', $resolver->resolve('other'));
        $this->assertSame(PricingStrategyResolver::SOURCE_SETTING, $resolver->source('other'));
    }

    public function test_the_management_endpoint_persists_resets_and_validates_the_overlay(): void
    {
        $token = $this->superAdminToken();
        $this->setStrategySetting('scope_licensing');

        // Write.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['pricing_strategy' => 'volume_licensing'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('effective.pricing_strategy', 'volume_licensing');

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.pricing_strategy',
            'brand' => 'rp',
            'value' => 'volume_licensing',
        ]);

        // The overlay is effective everywhere.
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
        ]);
        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);

        $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'volume_licensing');

        // The read endpoint reports the overlay and its source. The structure
        // assertion pins that the fields are *present*: a `null` value and a
        // missing key are indistinguishable in `assertJsonPath`.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonStructure([
                'brands' => [
                    0 => [
                        'defaults' => ['pricing_strategy'],
                        'overrides' => ['pricing_strategy'],
                        'effective' => ['pricing_strategy'],
                        'pricing_strategy_source',
                    ],
                ],
            ])
            ->assertJsonPath('brands.0.defaults.pricing_strategy', null)
            ->assertJsonPath('brands.0.overrides.pricing_strategy', 'volume_licensing')
            ->assertJsonPath('brands.0.effective.pricing_strategy', 'volume_licensing')
            ->assertJsonPath('brands.0.pricing_strategy_source', PricingStrategyResolver::SOURCE_OVERLAY);

        // Reset — the settings row governs again.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['pricing_strategy' => null])
            ->assertStatus(200)
            ->assertJsonPath('effective.pricing_strategy', 'scope_licensing');

        $this->assertDatabaseMissing('settings', [
            'key' => 'brand_config.pricing_strategy',
            'brand' => 'rp',
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/brand-settings')
            ->assertStatus(200)
            ->assertJsonStructure([
                'brands' => [
                    0 => [
                        'defaults' => ['pricing_strategy'],
                        'overrides' => [],
                        'effective' => ['pricing_strategy'],
                        'pricing_strategy_source',
                    ],
                ],
            ])
            // The overlay is gone: the overrides carry no pricing strategy at
            // all. The missing path is asserted explicitly — `assertJsonPath`
            // alone would also hold for a missing key.
            ->assertJsonMissingPath('brands.0.overrides.pricing_strategy')
            ->assertJsonPath('brands.0.overrides.pricing_strategy', null)
            ->assertJsonPath('brands.0.effective.pricing_strategy', 'scope_licensing')
            ->assertJsonPath('brands.0.pricing_strategy_source', PricingStrategyResolver::SOURCE_SETTING);

        $this->assertSame('scope_licensing', $gallery->fresh()->effective_licensing_mode);
    }

    public function test_a_write_that_does_not_touch_the_strategy_creates_no_overlay(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['name' => 'Neuer Markenname'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // The name override is written …
        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.name',
            'brand' => 'rp',
            'value' => 'Neuer Markenname',
        ]);

        // … and the strategy is left alone: no overlay row is created.
        $this->assertDatabaseMissing('settings', [
            'key' => 'brand_config.pricing_strategy',
            'brand' => 'rp',
        ]);
    }

    public function test_an_unknown_mode_is_rejected_with_422(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['pricing_strategy' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pricing_strategy']);

        $this->assertDatabaseMissing('settings', ['key' => 'brand_config.pricing_strategy']);
    }

    public function test_a_non_super_admin_cannot_write_the_overlay(): void
    {
        $token = $this->adminToken();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/brand-settings/rp', ['pricing_strategy' => 'volume_licensing'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('settings', ['key' => 'brand_config.pricing_strategy']);
    }

    public function test_the_overlay_is_isolated_per_brand(): void
    {
        $resolver = app(PricingStrategyResolver::class);

        // A brand id the config does not know still carries its own overlay.
        $this->brandSettings->apply('other', ['pricing_strategy' => 'volume_licensing']);

        $this->assertSame('volume_licensing', $resolver->resolve('other'));
        $this->assertSame(PricingStrategyResolver::SOURCE_OVERLAY, $resolver->source('other'));

        // The other brand is unaffected.
        $this->assertSame('scope_licensing', $resolver->resolve('rp'));
        $this->assertSame(PricingStrategyResolver::SOURCE_DEFAULT, $resolver->source('rp'));

        // The overlay rows are strictly separated by brand.
        $this->assertSame(
            'volume_licensing',
            Setting::where('key', 'brand_config.pricing_strategy')->where('brand', 'other')->value('value')
        );
        $this->assertNull(
            Setting::where('key', 'brand_config.pricing_strategy')->where('brand', 'rp')->value('value')
        );
    }

    public function test_the_gallery_own_licensing_mode_still_wins_over_the_brand_default(): void
    {
        $this->setStrategySetting('scope_licensing');
        $this->brandSettings->apply('rp', ['pricing_strategy' => 'volume_licensing']);

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'scope_licensing',
        ]);

        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);
    }

    public function test_the_overlay_is_reported_as_the_source_of_the_effective_mode(): void
    {
        $resolver = app(PricingStrategyResolver::class);

        $this->assertSame(PricingStrategyResolver::SOURCE_DEFAULT, $resolver->source('rp'));

        $this->setStrategySetting('volume_licensing');
        $this->assertSame(PricingStrategyResolver::SOURCE_SETTING, $resolver->source('rp'));

        $this->brandSettings->apply('rp', ['pricing_strategy' => 'volume_licensing']);
        $this->assertSame(PricingStrategyResolver::SOURCE_OVERLAY, $resolver->source('rp'));
        $this->assertSame('volume_licensing', $resolver->effectiveForBrand('rp'));
    }

    /**
     * The gallery mode resolves the brand default *with* the `rp` fallback.
     *
     * A gallery of a brand that is not `rp` inherits the canonical `rp` row;
     * a gallery whose own brand has a row resolves that row. The brand id is
     * deliberately not a configured brand — the fallback is a property of the
     * resolver, not of `config/brands.php`.
     */
    public function test_a_gallery_of_another_brand_resolves_the_rp_row_and_its_own_row(): void
    {
        $this->setStrategySetting('volume_licensing', 'rp');

        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => null,
            'brand' => 'srp',
        ]);

        // The gallery's own brand has no row: the canonical `rp` row governs.
        $this->assertSame('volume_licensing', $gallery->effective_licensing_mode);

        // The gallery's own row wins over the `rp` fallback.
        $this->setStrategySetting('scope_licensing', 'srp');
        $this->assertSame('scope_licensing', $gallery->effective_licensing_mode);
    }

    /**
     * The runtime binding and the public endpoint resolve the same brand.
     *
     * The host middleware normalises the context to a configured brand, so the
     * middleware is switched off here to reach a brand id outside
     * `App\Enums\Brand`: the binding must resolve the *active* context like
     * the endpoint does, and not silently fall back to `rp`.
     */
    public function test_the_runtime_binding_and_the_public_endpoint_resolve_the_same_brand(): void
    {
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(new BrandConfig(
            id: 'srp',
            name: 'SRP',
            theme: 'rp',
            portalName: 'SRP',
            impressumUrl: null,
            logoPath: null,
        ));

        $this->setStrategySetting('volume_licensing', 'rp');
        $this->setStrategySetting('scope_licensing', 'srp');

        $endpointMode = $this->getJson('/api/settings/license-pricing')
            ->assertStatus(200)
            ->json('pricing_strategy');

        $this->assertSame('scope_licensing', $endpointMode);
        $this->assertInstanceOf(ScopeLicensingStrategy::class, app(PricingStrategy::class));

        // The resolver's own context resolution is the one both share.
        $resolver = app(PricingStrategyResolver::class);
        $this->assertSame('srp', BrandRegistry::currentIdOrNull());
        $this->assertSame($resolver->resolve('srp'), $resolver->resolve());
    }
}
