<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Setting;
use Database\Seeders\SrpSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SrpSettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cleanup: SRP-Settings vor dem Test löschen
        DB::table('settings')->where('brand', Brand::SRP->value)->delete();
    }

    protected function tearDown(): void
    {
        // Cleanup: SRP-Settings nach dem Test löschen
        DB::table('settings')->where('brand', Brand::SRP->value)->delete();
        parent::tearDown();
    }

    public function test_volume_tier_settings_are_seeded_with_brand_srp(): void
    {
        // Act: Seeder ausführen
        $this->seed(SrpSettingsSeeder::class);

        // Assert: 5 Volumen-Staffel-Settings existieren mit brand='srp'
        $this->assertSame('3000',
            Setting::where('key', 'srp_price_per_image_tier1')->where('brand', 'srp')->value('value'));
        $this->assertSame('2500',
            Setting::where('key', 'srp_price_per_image_tier2')->where('brand', 'srp')->value('value'));
        $this->assertSame('2000',
            Setting::where('key', 'srp_price_per_image_tier3')->where('brand', 'srp')->value('value'));
        $this->assertSame('10',
            Setting::where('key', 'srp_tier_threshold1')->where('brand', 'srp')->value('value'));
        $this->assertSame('20',
            Setting::where('key', 'srp_tier_threshold2')->where('brand', 'srp')->value('value'));
    }

    public function test_rp_settings_are_copied_to_srp(): void
    {
        // Arrange: RP-Settings anlegen
        Setting::updateOrCreate(
            ['key' => 'price_web', 'brand' => Brand::B2B->value],
            ['value' => '7500']
        );
        Setting::updateOrCreate(
            ['key' => 'price_print', 'brand' => Brand::B2B->value],
            ['value' => '14500']
        );
        Setting::updateOrCreate(
            ['key' => 'mult_commercial', 'brand' => Brand::B2B->value],
            ['value' => '2.0']
        );

        // Act: Seeder ausführen
        $this->seed(SrpSettingsSeeder::class);

        // Assert: RP-Settings wurden nach SRP kopiert (stichprobenartig)
        $this->assertSame('7500',
            Setting::where('key', 'price_web')->where('brand', 'srp')->value('value'));
        $this->assertSame('14500',
            Setting::where('key', 'price_print')->where('brand', 'srp')->value('value'));
        $this->assertSame('2.0',
            Setting::where('key', 'mult_commercial')->where('brand', 'srp')->value('value'));
    }

    public function test_rp_specific_pricing_keys_are_not_copied(): void
    {
        // Arrange: RP-spezifischen Pricing-Key anlegen
        Setting::updateOrCreate(
            ['key' => 'license_use_case_special', 'brand' => Brand::B2B->value],
            ['value' => 'secret_rp_value']
        );

        // Act: Seeder ausführen
        $this->seed(SrpSettingsSeeder::class);

        // Assert: Der RP-spezifische Key wurde NICHT nach SRP kopiert
        $this->assertNull(
            Setting::where('key', 'license_use_case_special')->where('brand', 'srp')->value('value')
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        // Arrange: Seeder einmal ausführen
        $this->seed(SrpSettingsSeeder::class);
        $countAfterFirstRun = DB::table('settings')->where('brand', 'srp')->count();

        // Act: Seeder ein zweites Mal ausführen
        $this->seed(SrpSettingsSeeder::class);
        $countAfterSecondRun = DB::table('settings')->where('brand', 'srp')->count();

        // Assert: Keine Duplikate durch updateOrCreate
        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }
}
