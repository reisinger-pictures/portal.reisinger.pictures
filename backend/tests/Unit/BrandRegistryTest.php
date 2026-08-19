<?php

namespace Tests\Unit;

use App\Enums\Brand;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\BrandSettingsService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
    }

    public function test_from_host_falls_back_to_b2b_for_former_srp_domain(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('buy.reisinger.pictures'));
    }

    public function test_from_host_resolves_via_dev_fallback(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('srp.localhost'));
    }

    public function test_from_host_defaults_to_b2b_for_unknown_hosts(): void
    {
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.reisinger.pictures'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.localhost'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('buy.localhost'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('portal.test'));
        $this->assertSame(Brand::B2B, BrandRegistry::fromHost('example.com'));
    }

    public function test_current_returns_null_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertNull(BrandRegistry::current());
    }

    public function test_current_or_default_falls_back_to_b2b_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertSame(Brand::B2B, BrandRegistry::currentOrDefault());
    }

    public function test_current_returns_brand_from_container(): void
    {
        BrandRegistry::set(Brand::B2B);
        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }

    public function test_prefix_defaults_to_empty_when_unset(): void
    {
        BrandRegistry::set(null);
        $this->assertSame('', BrandRegistry::prefix());
    }

    public function test_set_with_null_clears_brand(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::set(null);
        $this->assertNull(BrandRegistry::current());
    }

    public function test_reset_clears_brand_to_null(): void
    {
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::reset();
        $this->assertNull(BrandRegistry::current());

        BrandRegistry::set(Brand::B2B);
        BrandRegistry::reset();
        $this->assertNull(BrandRegistry::current());
    }

    public function test_resolve_from_order_uses_persisted_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => Brand::B2B->value,
            'total_amount' => 100,
        ]);
        $this->assertSame(Brand::B2B, BrandRegistry::resolveFromOrder($order));
    }

    public function test_resolve_from_order_falls_back_to_b2b_for_null_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => null,
            'total_amount' => 100,
        ]);
        $this->assertSame(Brand::B2B, BrandRegistry::resolveFromOrder($order));
    }

    public function test_enum_prefix(): void
    {
        $this->assertSame('', Brand::B2B->prefix());
    }

    public function test_enum_id(): void
    {
        $this->assertSame('rp', Brand::B2B->id());
    }

    public function test_enum_domain(): void
    {
        $this->assertSame('portal.reisinger.pictures', Brand::B2B->domain());
    }

    // ── F3 — DB-Overlay (Option B) ───────────────────────────────────────────

    public function test_db_override_is_merged_over_config_default(): void
    {
        app(BrandSettingsService::class)->apply('rp', [
            'name' => 'Overlay Name',
            'primary_color' => '#123456',
        ]);

        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Overlay Name', $config->name);
        $this->assertSame('#123456', $config->primaryColor);

        // Config-only keys remain untouched by the overlay.
        $this->assertSame('rp', $config->theme);
        $this->assertSame('portal.reisinger.pictures', $config->hostnames[0]);
    }

    public function test_no_override_returns_config_default(): void
    {
        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Reisinger Pictures', $config->name);
        $this->assertTrue($config->features['orgs']);
    }

    public function test_features_orgs_override_reflected_in_has_feature(): void
    {
        app(BrandSettingsService::class)->apply('rp', [
            'features.orgs' => false,
        ]);

        $config = BrandRegistry::configForBrand('rp');
        $this->assertFalse($config->hasFeature('orgs'));
    }

    public function test_clear_cache_invalidates_memoized_override(): void
    {
        app(BrandSettingsService::class)->apply('rp', ['name' => 'Cached']);
        $this->assertSame('Cached', BrandRegistry::configForBrand('rp')->name);

        // Simulate a brand-settings write invalidating the memoized cache.
        Setting::where('key', 'brand_config.name')->where('brand', 'rp')->delete();
        BrandRegistry::clearCache();

        $this->assertSame('Reisinger Pictures', BrandRegistry::configForBrand('rp')->name);
    }
}
