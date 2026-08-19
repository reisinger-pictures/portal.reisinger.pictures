<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Setting;
use App\Services\BrandSettingsService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private BrandSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
        $this->service = app(BrandSettingsService::class);
    }

    public function test_override_persists_and_is_read_back_via_build_from_array(): void
    {
        $this->service->apply('rp', [
            'name' => 'Custom Name',
            'primary_color' => '#FF0000',
        ]);

        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Custom Name', $config->name);
        $this->assertSame('#FF0000', $config->primaryColor);

        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.name',
            'brand' => 'rp',
            'value' => 'Custom Name',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.primary_color',
            'brand' => 'rp',
            'value' => '#FF0000',
        ]);
    }

    public function test_config_default_is_fallback_when_no_override(): void
    {
        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Reisinger Pictures', $config->name);
        $this->assertSame('#1E5631', $config->primaryColor);

        $this->assertNull(
            Setting::where('key', 'brand_config.name')->where('brand', 'rp')->first()
        );
    }

    public function test_null_value_resets_override_and_deletes_row(): void
    {
        $this->service->apply('rp', ['name' => 'Custom Name']);
        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.name',
            'brand' => 'rp',
        ]);

        $this->service->apply('rp', ['name' => null]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'brand_config.name',
            'brand' => 'rp',
        ]);

        // Falls back to config default again.
        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Reisinger Pictures', $config->name);
    }

    public function test_merge_precedence_db_beats_config(): void
    {
        $this->assertSame('Reisinger Pictures', BrandRegistry::configForBrand('rp')->name);

        $this->service->apply('rp', ['name' => 'Override']);
        $this->assertSame('Override', BrandRegistry::configForBrand('rp')->name);

        // Re-applying a different value wins (update, not duplicate row).
        $this->service->apply('rp', ['name' => 'Override2']);
        $this->assertSame('Override2', BrandRegistry::configForBrand('rp')->name);
        $this->assertSame(1, Setting::where('key', 'brand_config.name')->where('brand', 'rp')->count());
    }

    public function test_features_orgs_override_is_cast_to_bool(): void
    {
        $this->service->apply('rp', ['features.orgs' => false]);
        $config = BrandRegistry::configForBrand('rp');
        $this->assertFalse($config->features['orgs']);
        $this->assertDatabaseHas('settings', [
            'key' => 'brand_config.features.orgs',
            'brand' => 'rp',
            'value' => '0',
        ]);

        $this->service->apply('rp', ['features.orgs' => true]);
        $config = BrandRegistry::configForBrand('rp');
        $this->assertTrue($config->features['orgs']);
        $this->assertSame('1', Setting::where('key', 'brand_config.features.orgs')->where('brand', 'rp')->value('value'));
    }

    public function test_non_whitelisted_keys_are_ignored(): void
    {
        $this->service->apply('rp', [
            'theme' => 'evil',      // config-only, must be ignored
            'name' => 'Allowed',
        ]);

        $config = BrandRegistry::configForBrand('rp');
        $this->assertSame('Allowed', $config->name);
        // theme is config-only and untouched.
        $this->assertSame('rp', $config->theme);
        $this->assertDatabaseMissing('settings', ['key' => 'brand_config.theme']);
    }

    public function test_no_cross_brand_leak(): void
    {
        $this->service->apply('rp', ['name' => 'RP Name']);
        $this->service->apply('other', ['name' => 'Other Name']);

        // rp is unaffected by the 'other' brand override.
        $rp = BrandRegistry::configForBrand('rp');
        $this->assertSame('RP Name', $rp->name);

        // DB rows are strictly isolated per brand.
        $this->assertSame('RP Name', Setting::where('key', 'brand_config.name')->where('brand', 'rp')->value('value'));
        $this->assertSame('Other Name', Setting::where('key', 'brand_config.name')->where('brand', 'other')->value('value'));
        $this->assertSame(1, Setting::where('key', 'brand_config.name')->where('brand', 'rp')->count());
        $this->assertSame(1, Setting::where('key', 'brand_config.name')->where('brand', 'other')->count());
    }

    public function test_public_brand_config_endpoint_reflects_override(): void
    {
        $this->service->apply('rp', [
            'primary_color' => '#ABCDEF',
            'name' => 'Overridden Portal',
        ]);

        $response = $this->getJson('/api/settings/brand-config');
        $response->assertOk();
        $response->assertJson([
            'id' => 'rp',
            'name' => 'Overridden Portal',
            'primary_color' => '#ABCDEF',
        ]);
    }
}
