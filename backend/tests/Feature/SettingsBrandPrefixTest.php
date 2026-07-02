<?php
namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsBrandPrefixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BrandContextMiddleware::class);
    }

    private function superAdminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(
            Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value])
        );
        return auth('api')->login($user);
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value])
        );
        return auth('api')->login($admin);
    }

    // ------------------------------------------------------------------
    // Bug (a): updateBillingDetails() — SRP prefix missing on write
    // ------------------------------------------------------------------

    public function test_billing_details_update_stores_brand_scoped_for_srp_brand(): void
    {
        BrandRegistry::set(Brand::SRP);
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'SRP Fotografie GmbH',
                'bank_iban' => 'AT11 2222 3333 4444 5555',
                'company_city' => 'Graz',
            ])
            ->assertStatus(200);

        // SRP brand: stored unprefixed under the srp brand column (no srp_ key prefixing).
        $this->assertSame('SRP Fotografie GmbH', Setting::where('key', 'bank_holder')->where('brand', 'srp')->value('value'));
        $this->assertSame('AT11 2222 3333 4444 5555', Setting::where('key', 'bank_iban')->where('brand', 'srp')->value('value'));
        $this->assertSame('Graz', Setting::where('key', 'company_city')->where('brand', 'srp')->value('value'));
        // The rp row is untouched by the SRP write (brand isolation).
        $this->assertNotSame('SRP Fotografie GmbH', Setting::where('key', 'bank_holder')->where('brand', 'rp')->value('value'));
    }

    public function test_billing_details_update_uses_unprefixed_for_b2b_brand(): void
    {
        BrandRegistry::set(Brand::B2B);
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'Reisinger GmbH',
                'bank_iban' => 'AT99 8888 7777 6666 5555',
            ])
            ->assertStatus(200);

        $this->assertSame('Reisinger GmbH', Setting::where('key', 'bank_holder')->value('value'));
        $this->assertSame('AT99 8888 7777 6666 5555', Setting::where('key', 'bank_iban')->value('value'));
    }

    public function test_billing_details_read_returns_brand_scoped_for_srp_brand(): void
    {
        BrandRegistry::set(Brand::SRP);
        // Use raw writes so both brand rows coexist independently (Setting's
        // primary key is `key`, so Eloquent updateOrCreate would cross-update rows).
        $db = \Illuminate\Support\Facades\DB::table('settings');
        $db->where('key', 'bank_holder')->delete();
        $db->insert([
            ['key' => 'bank_holder', 'brand' => 'srp', 'value' => 'SRP GmbH'],
            ['key' => 'bank_holder', 'brand' => 'rp', 'value' => 'B2B GmbH'],
        ]);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'SRP GmbH');
    }

    public function test_billing_details_read_falls_back_to_b2b_brand_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        // Only a B2B ('rp') row exists → SRP read falls back to it.
        $db = \Illuminate\Support\Facades\DB::table('settings');
        $db->where('key', 'bank_holder')->delete();
        $db->insert([
            'key' => 'bank_holder', 'brand' => 'rp', 'value' => 'Shared GmbH',
        ]);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'Shared GmbH');
    }

    // ------------------------------------------------------------------
    // Bug (b): updateLicenseTerms() — double prefix srp_srp_*
    // ------------------------------------------------------------------

    public function test_license_terms_update_maps_srp_keys_brand_scoped_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '2.0',
                'mult_unlimited' => '1.5',
                'mult_international' => '1.5',
                'srp_base_price' => '500',
                'srp_setup_fee' => '100',
            ])
            ->assertStatus(200);

        // Legacy srp_* request keys are mapped to unprefixed brand-scoped settings keys.
        $this->assertSame('500', Setting::where('key', 'base_price')->where('brand', 'srp')->value('value'));
        $this->assertSame('100', Setting::where('key', 'setup_fee')->where('brand', 'srp')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_srp_base_price')->value('value'));
    }

    public function test_license_terms_update_stores_unprefixed_keys_brand_scoped_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '3.0',
                'mult_unlimited' => '2.0',
                'mult_international' => '2.5',
                'calc_base_price' => '75',
            ])
            ->assertStatus(200);

        // Unprefixed request key stored under the SRP brand column (no key prefixing).
        $this->assertSame('75', Setting::where('key', 'calc_base_price')->where('brand', 'srp')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_calc_base_price')->value('value'));
        // The rp row is untouched by the SRP write (brand isolation).
        $this->assertNotSame('75', Setting::where('key', 'calc_base_price')->where('brand', 'rp')->value('value'));
    }

    public function test_license_terms_update_stores_brand_scoped_for_b2b(): void
    {
        BrandRegistry::set(Brand::B2B);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '2.0',
                'mult_unlimited' => '1.5',
                'mult_international' => '1.5',
                'srp_base_price' => '500',
            ])
            ->assertStatus(200);

        // Legacy srp_* request key maps to base_price, stored under the rp brand column.
        $this->assertSame('500', Setting::where('key', 'base_price')->where('brand', 'rp')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_srp_base_price')->value('value'));
    }

    // ------------------------------------------------------------------
    // Bug (c): updateWatermark() — watermark_opacity asymmetrical
    // ------------------------------------------------------------------

    public function test_watermark_opacity_write_is_brand_scoped_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => '0.75',
            ])
            ->assertStatus(200);

        // Stored unprefixed under the SRP brand column (no srp_ key prefixing).
        $this->assertSame('0.75', Setting::where('key', 'watermark_opacity')->where('brand', 'srp')->value('value'));
        $this->assertNull(Setting::where('key', 'watermark_opacity')->where('brand', 'rp')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_watermark_opacity')->value('value'));
    }

    public function test_watermark_opacity_read_after_write_is_brand_scoped_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        Setting::updateOrCreate(['key' => 'watermark_opacity', 'brand' => 'srp'], ['value' => '0.60']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.6);
    }

    public function test_watermark_opacity_read_falls_back_to_b2b_brand_for_srp(): void
    {
        BrandRegistry::set(Brand::SRP);
        // Only a B2B ('rp') row exists → SRP read falls back to it.
        Setting::updateOrCreate(['key' => 'watermark_opacity', 'brand' => 'rp'], ['value' => '0.30']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.3);
    }

    public function test_watermark_opacity_uses_unprefixed_for_b2b(): void
    {
        BrandRegistry::set(Brand::B2B);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => '0.50',
            ])
            ->assertStatus(200);

        $this->assertSame('0.50', Setting::where('key', 'watermark_opacity')->value('value'));
        $this->assertNull(Setting::where('key', 'srp_watermark_opacity')->value('value'));
    }

    public function test_watermark_opacity_default_is_015(): void
    {
        BrandRegistry::set(Brand::SRP);
        Setting::where('key', 'watermark_opacity')->delete();
        Setting::where('key', 'srp_watermark_opacity')->delete();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.15);
    }
}
