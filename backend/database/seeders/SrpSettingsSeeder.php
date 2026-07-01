<?php

namespace Database\Seeders;

use App\Enums\Brand;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * T-18 SRP Seeder-Dataset.
 *
 * 1. Kopiert alle relevanten RP-Settings (brand='rp' oder brand IS NULL) nach SRP.
 * 2. Seedt die 5 Volumen-Staffel-Settings für SRP (Volume Pricing).
 *
 * Siehe features/ ... (T-18).
 */
class SrpSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // Schritt 1: RP-Settings nach SRP kopieren (nur relevante Keys)
        // ------------------------------------------------------------------
        $rpSettings = DB::table('settings')
            ->whereIn('brand', [Brand::B2B->value, null])
            ->get();

        foreach ($rpSettings as $setting) {
            // RP-spezifische Pricing-Keys ausschließen (z.B. license_use_case_*)
            if (str_starts_with($setting->key, 'license_use_case_')) {
                continue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $setting->key, 'brand' => Brand::SRP->value],
                ['value' => $setting->value]
            );
        }

        // ------------------------------------------------------------------
        // Schritt 2: Volumen-Staffel-Werte seeden
        // ------------------------------------------------------------------
        $volumeTiers = [
            ['key' => 'srp_price_per_image_tier1', 'value' => '3000'],
            ['key' => 'srp_price_per_image_tier2', 'value' => '2500'],
            ['key' => 'srp_price_per_image_tier3', 'value' => '2000'],
            ['key' => 'srp_tier_threshold1',        'value' => '10'],
            ['key' => 'srp_tier_threshold2',        'value' => '20'],
        ];

        foreach ($volumeTiers as $tier) {
            Setting::updateOrCreate(
                ['key' => $tier['key'], 'brand' => Brand::SRP->value],
                ['value' => $tier['value']]
            );
        }

        // ------------------------------------------------------------------
        // Schritt 3: Pricing-Strategie pro Brand konfigurieren
        // ------------------------------------------------------------------
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'scope_licensing']
        );
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::SRP->value],
            ['value' => 'volume_licensing']
        );
    }
}
