<?php
namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsBrandPrefixTest extends TestCase
{
    use RefreshDatabase;

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
    // Bug (a): updateBillingDetails() — ATR prefix missing on write
    // ------------------------------------------------------------------

    public function test_billing_details_update_uses_atr_prefix_for_atr_brand(): void
    {
        config(['app.brand' => 'atr']);
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'ATR Fotografie GmbH',
                'bank_iban' => 'AT11 2222 3333 4444 5555',
                'company_city' => 'Graz',
            ])
            ->assertStatus(200);

        $this->assertNull(Setting::where('key', 'bank_holder')->value('value'));
        $this->assertSame('ATR Fotografie GmbH', Setting::where('key', 'atr_bank_holder')->value('value'));
        $this->assertSame('AT11 2222 3333 4444 5555', Setting::where('key', 'atr_bank_iban')->value('value'));
        $this->assertSame('Graz', Setting::where('key', 'atr_company_city')->value('value'));
    }

    public function test_billing_details_update_uses_unprefixed_for_b2b_brand(): void
    {
        config(['app.brand' => 'rp']);
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

    public function test_billing_details_read_returns_prefixed_for_atr_brand(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'atr_bank_holder'], ['value' => 'ATR GmbH']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B GmbH']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'ATR GmbH');
    }

    public function test_billing_details_read_falls_back_to_unprefixed_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Shared GmbH']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'Shared GmbH');
    }

    // ------------------------------------------------------------------
    // Bug (b): updateLicenseTerms() — double prefix atr_atr_*
    // ------------------------------------------------------------------

    public function test_license_terms_update_avoids_double_prefix_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '2.0',
                'mult_unlimited' => '1.5',
                'mult_international' => '1.5',
                'atr_base_price' => '500',
                'atr_setup_fee' => '100',
            ])
            ->assertStatus(200);

        $this->assertSame('500', Setting::where('key', 'atr_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_atr_base_price')->value('value'));
        $this->assertSame('100', Setting::where('key', 'atr_setup_fee')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_atr_setup_fee')->value('value'));
    }

    public function test_license_terms_update_prefixes_unprefixed_keys_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '3.0',
                'mult_unlimited' => '2.0',
                'mult_international' => '2.5',
                'calc_base_price' => '75',
            ])
            ->assertStatus(200);

        $this->assertSame('75', Setting::where('key', 'atr_calc_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'calc_base_price')->value('value'));
    }

    public function test_license_terms_update_does_not_prefix_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => '2.0',
                'mult_unlimited' => '1.5',
                'mult_international' => '1.5',
                'atr_base_price' => '500',
            ])
            ->assertStatus(200);

        $this->assertSame('500', Setting::where('key', 'atr_base_price')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_atr_base_price')->value('value'));
    }

    // ------------------------------------------------------------------
    // Bug (c): updateWatermark() — watermark_opacity asymmetrical
    // ------------------------------------------------------------------

    public function test_watermark_opacity_write_uses_prefix_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => '0.75',
            ])
            ->assertStatus(200);

        $this->assertNull(Setting::where('key', 'watermark_opacity')->value('value'));
        $this->assertSame('0.75', Setting::where('key', 'atr_watermark_opacity')->value('value'));
    }

    public function test_watermark_opacity_read_after_write_is_symmetrical_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'atr_watermark_opacity'], ['value' => '0.60']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.6);
    }

    public function test_watermark_opacity_read_falls_back_to_global_for_atr(): void
    {
        config(['app.brand' => 'atr']);
        Setting::updateOrCreate(['key' => 'watermark_opacity'], ['value' => '0.30']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.3);
    }

    public function test_watermark_opacity_uses_unprefixed_for_b2b(): void
    {
        config(['app.brand' => 'rp']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => '0.50',
            ])
            ->assertStatus(200);

        $this->assertSame('0.50', Setting::where('key', 'watermark_opacity')->value('value'));
        $this->assertNull(Setting::where('key', 'atr_watermark_opacity')->value('value'));
    }

    public function test_watermark_opacity_default_is_015(): void
    {
        config(['app.brand' => 'atr']);
        Setting::where('key', 'watermark_opacity')->delete();
        Setting::where('key', 'atr_watermark_opacity')->delete();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(200)
            ->assertJsonPath('opacity', 0.15);
    }
}
