<?php

namespace Database\Seeders;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\GalleryGroup;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\Product;
use App\Models\Role;
use App\Models\TextSnippet;
use App\Models\User;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {

        $adminEmail = config('admin.email');
        $adminPassword = config('admin.password');

        if (! is_string($adminEmail) || trim($adminEmail) === '' || ! is_string($adminPassword) || trim($adminPassword) === '') {
            throw new \RuntimeException('ADMIN_EMAIL und ADMIN_PASSWORD müssen für das Seeding gesetzt sein.');
        }

        // Admin-User seeden, um Race-Conditions in parallelen E2E-Tests zu vermeiden.
        // Die Passwort-Rotation übernimmt anschließend der explizite admin:update-Schritt.
        $adminUser = User::firstOrCreate(
            ['email' => $adminEmail],
            ['name' => 'Florian Reisinger', 'password' => Hash::make($adminPassword)]
        );

        $roles = array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::cases()
        );
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }

        // Admin-User erhält alle verfügbaren Rollen
        $adminUser->roles()->sync(Role::pluck('id')->toArray());

        $brand = BrandRegistry::currentOrDefault();

        // 1. Root-Gruppe "Privat" (strikt privat)
        $this->seedGalleryGroup('privat', [
            'name' => 'Privat',
            'is_public' => false,
        ], $brand);

        // 2. Root-Gruppe "Presse" (öffentlich)
        $presseGroup = $this->seedGalleryGroup('presse', [
            'name' => 'Presse',
            'is_public' => true,
        ], $brand);

        // 3. Untergruppen für Presse (Regionen)
        $oberoesterreich = $this->seedGalleryGroup('oberoesterreich', [
            'name' => 'Oberösterreich',
            'parent_id' => $presseGroup->id,
            'is_public' => true,
        ], $brand);

        $oesterreich = $this->seedGalleryGroup('oesterreich', [
            'name' => 'Österreich',
            'parent_id' => $presseGroup->id,
            'is_public' => true,
        ], $brand);

        $wien = $this->seedGalleryGroup('wien', [
            'name' => 'Wien',
            'parent_id' => $presseGroup->id,
            'is_public' => true,
        ], $brand);

        // 4. "Sport" als Meta-Galerien (GalleryGroup) anlegen
        $this->seedGalleryGroup('sport-oberoesterreich', [
            'name' => 'Sport',
            'parent_id' => $oberoesterreich->id,
            'is_public' => true,
        ], $brand);

        $this->seedGalleryGroup('sport-oesterreich', [
            'name' => 'Sport',
            'parent_id' => $oesterreich->id,
            'is_public' => true,
        ], $brand);

        $this->seedGalleryGroup('sport-wien', [
            'name' => 'Sport',
            'parent_id' => $wien->id,
            'is_public' => true,
        ], $brand);

        // --- Per-brand catalog, settings, CRM seed ---
        $this->seedCatalogForBrand($brand);

        // --- Volume-Licensing-Presets (Standard-Preset je Brand) ---
        app(VolumePresetService::class)->ensureDefaultPresetForBrand($brand);
    }

    /**
     * Seed a known group for one brand and repair a legacy NULL brand without
     * overwriting any other administrator-managed attributes.
     *
     * The lookup is brand-scoped, not slug-only: a gallery group is owned by a
     * single brand, so the same root slug must never be handed from one brand
     * to another. A slug-only `firstOrCreate` would make a second brand
     * silently inherit the first brand's group — and with it its `is_public`
     * policy and its whole subtree.
     *
     * Since V046 `gallery_groups.slug` is unique per `(brand, slug)`, so a slug
     * that already belongs to another brand is no obstacle: this brand gets its
     * own row with the same slug. Before V046 the schema was globally unique
     * and this method had to fail loudly instead; that limitation is gone.
     *
     * `null` is an ambiguous brand value: `AsBrand` allows it, and it is also
     * the historical marker of a pre-brand (legacy) row. The row's data alone
     * cannot tell "legacy NULL" from "intentionally cross-brand", so this
     * method does not pretend it can. For the seeder's own known slugs a NULL
     * row is overwhelmingly the legacy case, so it is repaired (claimed) —
     * the conservative reading, because the alternative would break the
     * documented legacy repair. An existing non-null brand is never
     * overwritten.
     */
    private function seedGalleryGroup(string $slug, array $attributes, Brand $brand): GalleryGroup
    {
        $ownGroup = GalleryGroup::query()
            ->where('slug', $slug)
            ->where('brand', $brand->value)
            ->first();

        if ($ownGroup !== null) {
            return $ownGroup;
        }

        $legacy = GalleryGroup::query()
            ->where('slug', $slug)
            ->whereNull('brand')
            ->first();

        if ($legacy !== null) {
            $legacy->brand = $brand;
            $legacy->save();

            return $legacy;
        }

        // Another brand may already own this slug (V046 makes `(brand, slug)`
        // the unique key). That is expected and safe: create this brand's own
        // row instead of refusing or reusing the foreign one.
        return GalleryGroup::create([
            'slug' => $slug,
            ...$attributes,
            'brand' => $brand,
        ]);
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
        // The seeder is authoritative for the settings rows it declares: `upsert`
        // on the (key, brand) primary key writes its own value, so a key an
        // earlier migration already created (V004: base_price/term_*, V005: the
        // bank keys) ends up with the seeder's value instead of the migration's.
        // `insertOrIgnore` silently skipped exactly those rows. A `db:seed`
        // therefore also overwrites values an operator set through the UI for
        // these 28 keys — see backend/AGENTS.md (Database Setup Policy).
        $settingDefaults = [
            'price_web' => '7500',
            'price_print' => '14500',
            'price_original' => '45000',
            'mult_commercial' => '2.0',
            'mult_unlimited' => '1.5',
            'mult_international' => '1.5',
            'term_web' => 'Web & Social Media (PR & Redaktionell). Längste Kante max. 2560px.',
            'term_print' => 'Print & Editorial (bis A4). Längste Kante max. 4000px.',
            'term_original' => 'Originalauflösung. Kommerzielle Werbung & uneingeschränkte Nutzung.',
            'term_territory_national' => 'Nutzung nur im Inland (national).',
            'term_territory_international' => 'Weltweite, uneingeschränkte räumliche Nutzung.',
            // Money is cents, whole-euro amounts included (owner decision
            // 2026-09-28). These two were euros — `'50'` / `'80'` — until
            // V045__calculator_money_fields_to_cents converted the stored rows;
            // V016__calculator_settings_defaults still seeds the euro values and
            // must not be edited, so the migration supersedes it.
            'calc_base_price' => '5000',
            'calc_hourly_rate' => '8000',
            'calc_images_per_hour' => '6',
            'calc_outdoor_images_per_hour' => '8',
            'calc_flatrate_multiplier' => '1.2',
            // Per-image license base prices are stored in cents.
            'base_price' => '8000',
            'setup_fee' => '5000',
            'privacy_fee' => '20000',
            'extra_image_fee' => '1500',
            'bank_holder' => 'Florian Reisinger',
            'company_street' => 'Robert-Stolz-Straße 8',
            'company_zip' => '4020',
            'company_city' => 'Linz',
            'company_country' => 'Österreich',
            'company_email' => 'admin@example.com',
            'bank_iban' => 'DE96100110012179986174',
            'bank_bic' => 'NTSBDEB1XXX',
        ];

        $settingRows = array_map(
            static fn (string $key, string $value): array => [
                'key' => $key,
                'brand' => $brandCode,
                'value' => $value,
            ],
            array_keys($settingDefaults),
            array_values($settingDefaults)
        );
        // `settings` has a composite primary key (key, brand) — V019 restored it
        // after V018 dropped it — so (key, brand) is a valid upsert conflict
        // target on SQLite as well as MySQL/MariaDB. Idempotent: a repeated
        // seed updates the rows it owns instead of failing on the primary key.
        DB::table('settings')->upsert($settingRows, ['key', 'brand'], ['value']);

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
            Product::firstOrCreate(
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
            LicenseUseCase::firstOrCreate(
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
            LicenseModifier::firstOrCreate(
                ['name' => $mod['name'], 'brand' => $brandCode],
                array_merge($mod, ['brand' => $brandCode])
            );
        }

        // --- CRM: placeholder customer + text snippet so the brand is not empty ---
        Customer::firstOrCreate(
            ['email' => 'beispiel-'.$brandCode.'@reisinger.pictures', 'brand' => $brandCode],
            ['name' => 'Beispiel-Kunde ('.strtoupper($brandCode).')', 'company' => 'Reisinger Pictures', 'brand' => $brandCode]
        );
        TextSnippet::firstOrCreate(
            ['shortcut' => 'begr-'.$brandCode, 'brand' => $brandCode],
            ['title' => 'Begrüßung ('.strtoupper($brandCode).')', 'content_html' => '<p>Hallo und willkommen!</p>', 'brand' => $brandCode]
        );
    }
}
