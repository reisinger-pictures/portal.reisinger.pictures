<?php

namespace Database\Seeders;

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
        $roles = [\App\Enums\UserRole::SUPER_ADMIN->value, \App\Enums\UserRole::ADMIN->value, \App\Enums\UserRole::PHOTOGRAPHER->value, \App\Enums\UserRole::CLIENT->value, \App\Enums\UserRole::CUSTOMER_MANAGER->value, \App\Enums\UserRole::POWER_USER->value];
        foreach ($roles as $roleName) {
            \App\Models\Role::firstOrCreate(['name' => $roleName]);
        }

        // Admin-User erhält alle verfügbaren Rollen
        $adminUser->roles()->sync(\App\Models\Role::pluck('id')->toArray());

        // ATR brand tenant (all-the.rest) — B2C counterpart to the B2B portal.
        \App\Models\Tenant::firstOrCreate(
            ['domain' => 'all-the.rest'],
            [
                'name' => 'all-the.rest',
                'brand' => \App\Enums\Brand::ATR,
            ]
        );

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

        // Standard-Lizenzen & Preise
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_web'], ['value' => '7500']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_print'], ['value' => '14500']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'price_original'], ['value' => '45000']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_commercial'], ['value' => '2.0']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_unlimited'], ['value' => '1.5']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'mult_international'], ['value' => '1.5']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_web'], ['value' => 'Web & Social Media (PR & Redaktionell). Längste Kante max. 2560px.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_print'], ['value' => 'Print & Editorial (bis A4). Längste Kante max. 4000px.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_original'], ['value' => 'Originalauflösung. Kommerzielle Werbung & uneingeschränkte Nutzung.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_territory_national'], ['value' => 'Nutzung nur im Inland (national).']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'term_territory_international'], ['value' => 'Weltweite, uneingeschränkte räumliche Nutzung.']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_base_price'], ['value' => '50']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_hourly_rate'], ['value' => '80']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_images_per_hour'], ['value' => '6']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'calc_outdoor_multiplier'], ['value' => '0.5']);

        // Reale Impressums- und Bankdaten für den Checkout seeden
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_holder'], ['value' => 'Florian Reisinger']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_street'], ['value' => 'Robert-Stolz-Straße 8']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_zip'], ['value' => '4020']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_city'], ['value' => 'Linz']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_country'], ['value' => 'Österreich']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'company_email'], ['value' => 'florian@reisinger.pictures']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_iban'], ['value' => 'DE96100110012179986174']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(['key' => 'bank_bic'], ['value' => 'NTSBDEB1XXX']);

        // --- Produkte & Katalog (Preise, Pakete, Rabatte) ---
        $this->command->info('Seede Produkt-Katalog...');
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
                ['name' => $product['name']],
                $product
            );
        }

        // Neu: Trigger den Location Import direkt im Seed
        $this->command->info('Starte Smart Assistance Import...');
        \Illuminate\Support\Facades\Artisan::call('app:import-locations', [], $this->command->getOutput());
    }
}
