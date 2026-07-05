<?php

namespace Database\Seeders;

use App\Enums\Brand;
use Illuminate\Database\Seeder;
use App\Models\GalleryGroup;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {

        // Admin-User seeden, um Race-Conditions in parallelen E2E-Tests zu vermeiden
        $adminUser = \App\Models\User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'florian@reisinger.pictures')],
            ['name' => 'Florian Reisinger', 'password' => \Illuminate\Support\Facades\Hash::make(env('ADMIN_PASSWORD', 'admin'))]
        );
        $roles = [\App\Enums\UserRole::SUPER_ADMIN->value, \App\Enums\UserRole::ADMIN->value, \App\Enums\UserRole::PHOTOGRAPHER->value, \App\Enums\UserRole::CLIENT->value, \App\Enums\UserRole::ORG_ADMIN->value, \App\Enums\UserRole::POWER_USER->value];
        foreach ($roles as $roleName) {
            \App\Models\Role::firstOrCreate(['name' => $roleName]);
        }

        // Admin-User erhält alle verfügbaren Rollen
        $adminUser->roles()->sync(\App\Models\Role::pluck('id')->toArray());

        // 1. Root-Gruppe "Privat" (strikt privat)
        $privatGroup = GalleryGroup::firstOrCreate(
            ['slug' => 'privat'],
            ['name' => 'Privat', 'is_public' => false]
        );

        // 2. Root-Gruppe "Presse" (öffentlich)
        $presseGroup = GalleryGroup::firstOrCreate(
            ['slug' => 'presse'],
            ['name' => 'Presse', 'is_public' => true]
        );

        // 3. Untergruppen für Presse (Regionen)
        $oberoesterreich = GalleryGroup::firstOrCreate(
            ['slug' => 'oberoesterreich'],
            ['name' => 'Oberösterreich', 'parent_id' => $presseGroup->id, 'is_public' => true]
        );

        $oesterreich = GalleryGroup::firstOrCreate(
            ['slug' => 'oesterreich'],
            ['name' => 'Österreich', 'parent_id' => $presseGroup->id, 'is_public' => true]
        );

        $wien = GalleryGroup::firstOrCreate(
            ['slug' => 'wien'],
            ['name' => 'Wien', 'parent_id' => $presseGroup->id, 'is_public' => true]
        );

        // 4. "Sport" als Meta-Galerien (GalleryGroup) anlegen
        GalleryGroup::firstOrCreate(
            ['slug' => 'sport-oberoesterreich'],
            ['name' => 'Sport', 'parent_id' => $oberoesterreich->id, 'is_public' => true]
        );

        GalleryGroup::firstOrCreate(
            ['slug' => 'sport-oesterreich'],
            ['name' => 'Sport', 'parent_id' => $oesterreich->id, 'is_public' => true]
        );

        GalleryGroup::firstOrCreate(
            ['slug' => 'sport-wien'],
            ['name' => 'Sport', 'parent_id' => $wien->id, 'is_public' => true]
        );

        // --- Per-brand catalog, settings, CRM seed (spec §5) ---
        // B2B ('rp') = canonical/existing data; SRP ('srp') = placeholder copy
        $this->seedCatalogForBrand(Brand::B2B);
        $this->seedCatalogForBrand(Brand::SRP);

        // T-18: SRP-spezifische Settings (Volumen-Staffel + RP-Defaults kopieren)
        $this->call(SrpSettingsSeeder::class);

        // Neu: Trigger den Location Import direkt im Seed
        $this->command->info('Starte Smart Assistance Import...');
        \Illuminate\Support\Facades\Artisan::call('app:import-locations', [], $this->command->getOutput());
    }

    /**
     * Seed catalog, settings and CRM rows for a single brand (spec §5).
     * Each row carries the brand explicitly. SRP currently receives a placeholder
     * copy of the rp dataset; the concrete SRP catalog/prices are delivered via T-18.
     */
    private function seedCatalogForBrand(Brand $brand): void
    {
        $brandCode = $brand->value;
        $this->command->info("Seede Katalog/Settings für Brand '{$brandCode}'...");

        // --- Standard-Lizenzen & Preise (brand-scoped via the brand column) ---
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_web', 'brand' => $brandCode], ['value' => '7500']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_print', 'brand' => $brandCode], ['value' => '14500']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_original', 'brand' => $brandCode], ['value' => '45000']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_commercial', 'brand' => $brandCode], ['value' => '2.0']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_unlimited', 'brand' => $brandCode], ['value' => '1.5']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_international', 'brand' => $brandCode], ['value' => '1.5']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_web', 'brand' => $brandCode], ['value' => 'Web & Social Media (PR & Redaktionell). Längste Kante max. 2560px.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_print', 'brand' => $brandCode], ['value' => 'Print & Editorial (bis A4). Längste Kante max. 4000px.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_original', 'brand' => $brandCode], ['value' => 'Originalauflösung. Kommerzielle Werbung & uneingeschränkte Nutzung.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_territory_national', 'brand' => $brandCode], ['value' => 'Nutzung nur im Inland (national).']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_territory_international', 'brand' => $brandCode], ['value' => 'Weltweite, uneingeschränkte räumliche Nutzung.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_base_price', 'brand' => $brandCode], ['value' => '50']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_hourly_rate', 'brand' => $brandCode], ['value' => '80']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_images_per_hour', 'brand' => $brandCode], ['value' => '6']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_outdoor_multiplier', 'brand' => $brandCode], ['value' => '0.5']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_flatrate_multiplier', 'brand' => $brandCode], ['value' => '1.2']);
        // Per-image license base prices (used by SRP/Calculator; defaults match V010 comments).
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'base_price', 'brand' => $brandCode], ['value' => '8000']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'setup_fee', 'brand' => $brandCode], ['value' => '5000']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'privacy_fee', 'brand' => $brandCode], ['value' => '20000']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'extra_image_fee', 'brand' => $brandCode], ['value' => '1500']);

        // Reale Impressums- und Bankdaten für den Checkout seeden
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_holder', 'brand' => $brandCode], ['value' => 'Florian Reisinger']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_street', 'brand' => $brandCode], ['value' => 'Robert-Stolz-Straße 8']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_zip', 'brand' => $brandCode], ['value' => '4020']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_city', 'brand' => $brandCode], ['value' => 'Linz']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_country', 'brand' => $brandCode], ['value' => 'Österreich']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_email', 'brand' => $brandCode], ['value' => 'florian@reisinger.pictures']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_iban', 'brand' => $brandCode], ['value' => 'DE96100110012179986174']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_bic', 'brand' => $brandCode], ['value' => 'NTSBDEB1XXX']);

        // --- Produkte & Katalog (Preise, Pakete, Rabatte) ---
        $products = [
            // Pakete (Fixpreise)
            ['type' => 'item', 'name' => 'Dein (Mini) Shooting', 'description' => 'Bis zu 60 Min. | 3 Bilder', 'price' => 19900],
            ['type' => 'item', 'name' => 'n*xt Creative Special', 'description' => 'Bis zu 90 Min. | 15 Bilder | Nur 18-25 J. inkl. Veröffentlichung', 'price' => 30000],
            ['type' => 'item', 'name' => 'Dein Shooting', 'description' => 'Bis zu 150 Min. | 15 Bilder', 'price' => 49900],
            ['type' => 'item', 'name' => 'N*xt Image (Social Media Special)', 'description' => '30 Min. | 2 Bilder', 'price' => 9900],

            // Stundensätze & B2B
            ['type' => 'item', 'name' => 'B2B Business-Shooting', 'description' => 'Professionelle Bildbearbeitung, Volle Nutzungsrechte (Presse & PR)', 'price' => 15000],
            ['type' => 'item', 'name' => 'Privat-Shooting', 'description' => 'Zusätzliche Zeit / Individuelle Verlängerung', 'price' => 10000],

            // Upsells & Add-ons
            ['type' => 'item', 'name' => 'Zusatzbild (+1 Bild)', 'price' => 2900],
            ['type' => 'item', 'name' => 'Zusatzbilder Paket (+5 Bilder)', 'price' => 12500],
            ['type' => 'item', 'name' => 'Zusatzbilder Paket (+10 Bilder)', 'price' => 19900],
            ['type' => 'item', 'name' => '48h Express Service', 'price' => 29900],
            ['type' => 'item', 'name' => 'Alle Fotos (unbearbeitet JPEG)', 'description' => 'Alle Bilder des Shootings als JPEGs ohne Bearbeitung', 'price' => 159900],

            // Rabatte (Prozentual)
            ['type' => 'discount_percent', 'name' => 'Special Deal OGs (50%)', 'description' => 'Für langjährige Wegbegleiter (inkl. Freigabe)', 'price' => 5000],
            ['type' => 'discount_percent', 'name' => 'OG Hochzeit (33%)', 'description' => 'Treue-Rabatt für Hochzeitsreportagen', 'price' => 3333],
            ['type' => 'discount_percent', 'name' => 'Nxt Generation Rabatt (33%)', 'description' => 'Für 18-25 Jährige (Inkl. Freigabe)', 'price' => 3333],

            // Rabatte (Fixbeträge / Guthaben)
            ['type' => 'discount_fixed', 'name' => 'Feedback Bonus (Google)', 'description' => 'Dankeschön für eine Bewertung', 'price' => 3000],
            ['type' => 'discount_fixed', 'name' => 'Friends of Friends Voucher', 'description' => 'Everyone can be n*xt', 'price' => 15000],
        ];

        foreach ($products as $product) {
            \App\Models\Product::firstOrCreate(
                ['name' => $product['name'], 'brand' => $brandCode],
                array_merge($product, ['brand' => $brandCode])
            );
        }

        // --- License use cases & modifiers (per-image licensing) ---
        // Defaults reflect V010 seed comments (Tageszeitungen, Corporate Publishing,
        // Web & Social, Werbung/Kampagne). base_price values in cents.
        $useCases = [
            ['name' => 'Tageszeitungen', 'description' => 'Print-Nutzung in Tageszeitungen (redaktionell).', 'base_price' => 8000, 'flatrate_tier' => 'print', 'sort_order' => 10, 'is_commercial' => false],
            ['name' => 'Corporate Publishing', 'description' => 'Print-Nutzung kommerziell (Geschäftsberichte, Broschüren).', 'base_price' => 15000, 'flatrate_tier' => 'print', 'sort_order' => 20, 'is_commercial' => true],
            ['name' => 'Web & Social', 'description' => 'Digitale Nutzung (Web, Social Media, PR).', 'base_price' => 4500, 'flatrate_tier' => 'web', 'sort_order' => 30, 'is_commercial' => false],
            ['name' => 'Werbung / Kampagne', 'description' => 'Kommerzielle Werbung & Kampagnen (Originalauflösung).', 'base_price' => 45000, 'flatrate_tier' => 'original', 'sort_order' => 40, 'is_commercial' => true],
        ];
        foreach ($useCases as $uc) {
            \App\Models\LicenseUseCase::firstOrCreate(
                ['name' => $uc['name'], 'brand' => $brandCode],
                array_merge($uc, ['brand' => $brandCode])
            );
        }

        $modifiers = [
            ['name' => 'Erweiterte Nutzungsrechte', 'description' => 'Erweiterung der Nutzungsrechte.', 'percent_surcharge' => 50.0, 'is_included_in_flatrate' => false, 'sort_order' => 10],
            ['name' => 'Exklusivnutzung', 'description' => 'Ausschließliche Nutzung (keine Mitbewerber).', 'percent_surcharge' => 100.0, 'is_included_in_flatrate' => false, 'sort_order' => 20],
            ['name' => 'Eilzuschlag', 'description' => 'Express-Bearbeitung.', 'percent_surcharge' => 25.0, 'is_included_in_flatrate' => false, 'sort_order' => 30],
        ];
        foreach ($modifiers as $mod) {
            \App\Models\LicenseModifier::firstOrCreate(
                ['name' => $mod['name'], 'brand' => $brandCode],
                array_merge($mod, ['brand' => $brandCode])
            );
        }

        // --- CRM: placeholder customer + text snippet so the brand is not empty ---
        \App\Models\Customer::firstOrCreate(
            ['email' => 'beispiel-' . $brandCode . '@reisinger.pictures', 'brand' => $brandCode],
            ['name' => 'Beispiel-Kunde (' . strtoupper($brandCode) . ')', 'company' => 'Reisinger Pictures', 'brand' => $brandCode]
        );
        \App\Models\TextSnippet::firstOrCreate(
            ['shortcut' => 'begr-' . $brandCode, 'brand' => $brandCode],
            ['title' => 'Begrüßung (' . strtoupper($brandCode) . ')', 'content_html' => '<p>Hallo und willkommen!</p>', 'brand' => $brandCode]
        );
    }
}
