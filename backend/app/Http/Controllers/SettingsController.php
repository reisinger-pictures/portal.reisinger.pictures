<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrandSettingsRequest;
use App\Jobs\InvalidateWatermarkCacheJob;
use App\Models\Gallery;
use App\Services\BrandSettingsService;
use App\Services\SettingResolver;
use App\Services\VolumePresetService;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

class SettingsController extends Controller
{
    /**
     * Validation rule per **settings key** — deliberately not per the name the key
     * carries in a request. A legacy `srp_*` spelling is only an alternative name
     * for one of these keys and inherits that key's rule, so a client cannot pick
     * a weaker rule by picking an older name
     * (features/infrastructure/28-settings-key-meaning.md).
     *
     * `setup_fee`, `privacy_fee` and `extra_image_fee` are listed because the
     * endpoint *writes* them, not because they are request names — their only
     * request spelling is the legacy one (see LEGACY_ONLY_KEYS).
     */
    private const LICENSE_TERM_RULES = [
        'base_price' => 'nullable|integer|min:500',
        // The two studio-calculator money fields, in cents like every other
        // money key (owner decision 2026-09-28) and held to exactly the rule
        // `base_price` already carries. `integer` is the unit guard: a
        // fractional amount means the caller sent euros, and storing that
        // would be off by two decimal orders of magnitude. It was `numeric`
        // before, which is what let a client pick a weaker rule for a money
        // field than its own sibling in the same form — the defect the
        // settings-key-meaning decision (features/infrastructure/28) is about.
        // `min:500` (5 €) matches `base_price`; a deliberately free tier is
        // not a configuration the calculator can price, and the write path has
        // always rejected the sub-minimum for the other cent fields.
        'calc_base_price' => 'nullable|integer|min:500',
        'calc_hourly_rate' => 'nullable|integer|min:500',
        'calc_images_per_hour' => 'nullable|integer|min:1',
        'calc_outdoor_images_per_hour' => 'nullable|integer|min:1',
        'calc_flatrate_multiplier' => 'nullable|numeric|min:1|max:10',
        'term_editorial' => 'nullable|string',
        'term_commercial' => 'nullable|string',
        'term_1_year' => 'nullable|string',
        'term_unlimited' => 'nullable|string',
        'term_territory_national' => 'nullable|string',
        'term_territory_international' => 'nullable|string',
        'mult_commercial' => 'required|numeric|min:1',
        'mult_unlimited' => 'required|numeric|min:1',
        'mult_international' => 'required|numeric|min:1',
        'term_web' => 'nullable|string',
        'term_print' => 'nullable|string',
        'term_original' => 'nullable|string',
        // Resolution prices in cents, the unit the licence card submits
        // (`Math.round(euros * 100)`) and the table stores. `integer` is
        // the unit guard: a fractional amount means a caller sent euros,
        // and storing that would be off by two decimal orders of
        // magnitude. `nullable` (write-when-present) rather than
        // `required` because this endpoint is also written by clients that
        // send a partial payload — the shooting calculator's save carries
        // only `calc_*` plus the `mult_*` multipliers — and because the
        // three keys reach the table solely through the idempotent
        // `DatabaseSeeder`, so a row that predates them must not turn
        // every save into a 422. `min:0` matches the card's `min="0"`
        // input, so a deliberately free tier stays saveable.
        'price_web' => 'nullable|integer|min:0',
        'price_print' => 'nullable|integer|min:0',
        'price_original' => 'nullable|integer|min:0',
        // The three SRP fees are cent amounts like `price_*`, not euros: the
        // calculator card submits `Math.round(euros * 100)`, the seeder stores
        // 5000 / 20000 / 1500, and every consumer reads them back as
        // `Number(value)/100`. `integer` is therefore the same unit guard
        // `price_*` already carries — `numeric` would admit a fractional cent
        // (`'5000.5'`) that no client can produce and no consumer can
        // represent, and store it indistinguishably from a validated amount.
        // `min:0` stays, matching the card's `min="0"` input, so a
        // deliberately free setup fee remains saveable.
        'setup_fee' => 'nullable|integer|min:0',
        'privacy_fee' => 'nullable|integer|min:0',
        'extra_image_fee' => 'nullable|integer|min:0',
    ];

    /**
     * Legacy `srp_*` request spelling => the settings key it writes.
     *
     * Pure backwards compatibility with the pre-rename frontend payload, not a
     * second API (spec §3.2/§6): both spellings write the same key and are held
     * to the same rule. The read endpoint keeps serving both names.
     */
    private const LEGACY_REQUEST_SPELLINGS = [
        'srp_base_price' => 'base_price',
        'srp_setup_fee' => 'setup_fee',
        'srp_privacy_fee' => 'privacy_fee',
        'srp_extra_image_fee' => 'extra_image_fee',
    ];

    /**
     * Settings keys the endpoint writes that are *not* request names: the only
     * way a client can write them is the legacy `srp_*` spelling, so they must
     * not become an additional spelling of the API. `base_price` is
     * deliberately absent — it is a request name in its own right *and* the
     * target of `srp_base_price`.
     */
    private const LEGACY_ONLY_KEYS = [
        'setup_fee',
        'privacy_fee',
        'extra_image_fee',
    ];

    public function getSystemInfo()
    {
        // Build time = newest modification time of ANY PHP file in the backend
        // (the deployed source). Runtime-generated directories (vendor, storage,
        // node_modules, bootstrap/cache) are excluded so log writes, cache
        // flushes or composer installs never masquerade as a code deploy.
        // Cached forever for performance, but the cache is cleared on every
        // container start (php artisan cache:clear in the backend command block
        // of docker-compose.yml) — a deploy + restart therefore always shows the
        // fresh timestamp. Without that reset the value froze at the first
        // request and survived rclone syncs (2026-08-17 regression).
        $timestamp = Cache::rememberForever('laravel_build_time', function () {
            $finder = Finder::create()
                ->files()
                ->in(base_path())
                ->name('*.php')
                ->exclude(['vendor', 'storage', 'node_modules', 'bootstrap/cache', 'out']);
            $maxTime = 0;
            foreach ($finder as $file) {
                $time = $file->getMTime();
                if ($time > $maxTime) {
                    $maxTime = $time;
                }
            }

            return $maxTime ?: time();
        });

        $latestMigration = DB::table('migrations')->orderBy('id', 'desc')->value('migration');
        $dbVersion = DB::table('migrations')->count();
        if ($latestMigration && preg_match('/V(\d+)__/', $latestMigration, $matches)) {
            $dbVersion = (int) $matches[1];
        }

        return response()->json([
            'laravel_build_time' => date('c', $timestamp),
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'db_version' => $dbVersion,
        ]);
    }

    public function getWatermarkSvg()
    {
        $pfx = BrandRegistry::prefix();
        $path = Storage::disk('photos')->path('_watermarks/'.$pfx.'watermark.svg');
        if (! file_exists($path)) {
            $path = Storage::disk('photos')->path('_watermarks/watermark.svg');
        }
        if (! file_exists($path)) {
            abort(404);
        }

        // The SVG is user-uploaded; serve it with a restrictive CSP + nosniff so
        // a malicious SVG can never execute script when opened directly.
        return response()->file($path, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function getWatermark(SettingResolver $resolver)
    {
        $pfx = BrandRegistry::prefix();
        $disk = Storage::disk('photos');

        return response()->json([
            'has_svg' => $disk->exists('_watermarks/'.$pfx.'watermark.svg'),
            'opacity' => (float) $resolver->get('watermark_opacity', 0.15),
        ]);
    }

    public function updateWatermark(Request $request, SettingResolver $resolver)
    {
        $request->validate([
            'opacity' => 'nullable|numeric|min:0.05|max:1.0',
            'svg' => 'nullable|file|mimes:svg|max:512',
            'bucket_500' => 'nullable|file',
            'bucket_1000' => 'nullable|file',
            'bucket_2000' => 'nullable|file',
            'bucket_500_sel' => 'nullable|file',
            'bucket_1000_sel' => 'nullable|file',
            'bucket_2000_sel' => 'nullable|file',
        ]);

        if ($request->has('opacity')) {
            $resolver->set('watermark_opacity', $request->opacity);
        }

        $disk = Storage::disk('photos');
        $dir = '_watermarks';
        if (! $disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }

        $pfx = BrandRegistry::prefix();
        if ($request->hasFile('svg')) {
            $disk->putFileAs($dir, $request->file('svg'), $pfx.'watermark.svg');
        }
        if ($request->hasFile('bucket_500')) {
            $disk->putFileAs($dir, $request->file('bucket_500'), $pfx.'master_500.png');
        }
        if ($request->hasFile('bucket_1000')) {
            $disk->putFileAs($dir, $request->file('bucket_1000'), $pfx.'master_1000.png');
        }
        if ($request->hasFile('bucket_2000')) {
            $disk->putFileAs($dir, $request->file('bucket_2000'), $pfx.'master_2000.png');
        }
        if ($request->hasFile('bucket_500_sel')) {
            $disk->putFileAs($dir, $request->file('bucket_500_sel'), $pfx.'master_selection_500.png');
        }
        if ($request->hasFile('bucket_1000_sel')) {
            $disk->putFileAs($dir, $request->file('bucket_1000_sel'), $pfx.'master_selection_1000.png');
        }
        if ($request->hasFile('bucket_2000_sel')) {
            $disk->putFileAs($dir, $request->file('bucket_2000_sel'), $pfx.'master_selection_2000.png');
        }

        // Cache-Busting: Lösche alle generierten Wasserzeichen-Bilder asynchron
        // im Hintergrund. Der Job prüft beide Löschvorgänge und wird bei einem
        // verbleibenden Verzeichnis erneut versucht; ein terminaler Fehler wird
        // über den Queue-Failure-Handler protokolliert.
        InvalidateWatermarkCacheJob::dispatch();

        return response()->json(['success' => true]);
    }

    /**
     * Public brand configuration for the current brand.
     * Used by the frontend to resolve theme, feature flags, portal name, etc.
     */
    public function getBrandConfig()
    {
        $config = BrandRegistry::configOrDefault();

        return response()->json([
            'id' => $config->id,
            'name' => $config->name,
            'theme' => $config->theme,
            'portal_name' => $config->portalName,
            'impressum_url' => $config->impressumUrl,
            'logo_path' => $config->logoPath,
            'features' => (object) $config->features,
            'primary_color' => $config->primaryColor,
            'secondary_color' => $config->secondaryColor,
        ]);
    }

    /**
     * R-01 (security/naming): Lizenzbedingungen + Preisfaktoren — KEINE Bank-/Firmendaten.
     * Öffentlich (Gallery-/License-Selector-/Calculator-Flows). Sensible Billing-/Impressum-Daten
     * liegen bewusst im separaten, auth-geschützten Endpunkt getBillingDetails() (SRP-Trennung).
     */
    public function getLicenseTerms(Request $request, SettingResolver $resolver)
    {
        $galleryId = $request->query('gallery_id');

        $pricingStrategy = $resolver->get('pricing_strategy') ?? 'scope_licensing';
        $gallery = null;

        if ($galleryId !== null) {
            $gallery = Gallery::find($galleryId);
            if ($gallery !== null) {
                $galleryBrand = BrandRegistry::normalizeId($gallery->brand);
                $parentTreeMatches = $gallery->gallery_group_id === null
                    || BrandRegistry::galleryGroupTreeMatchesCurrent($gallery->galleryGroup()->first());

                // Keep the established legacy-null gallery fallback for the
                // gallery's own brand, but never let a foreign/null parent
                // influence this host's licensing terms or volume preset.
                if (($galleryBrand !== null && ! BrandRegistry::resourceMatchesCurrent($galleryBrand))
                    || ! $parentTreeMatches) {
                    $gallery = null;
                } else {
                    $pricingStrategy = $gallery->effective_licensing_mode;
                }
            }
        }

        $presetService = app(VolumePresetService::class);
        $preset = $pricingStrategy === 'volume_licensing'
            ? $presetService->resolveForGallery($gallery)
            : null;

        return response()->json([
            'editorial' => $resolver->get('term_editorial'),
            'commercial' => $resolver->get('term_commercial'),
            '1_year' => $resolver->get('term_1_year'),
            'unlimited' => $resolver->get('term_unlimited'),
            'territory_national' => $resolver->get('term_territory_national'),
            'territory_international' => $resolver->get('term_territory_international'),
            'mult_commercial' => $resolver->get('mult_commercial'),
            'mult_unlimited' => $resolver->get('mult_unlimited'),
            'mult_international' => $resolver->get('mult_international'),
            'web' => $resolver->get('term_web'),
            'print' => $resolver->get('term_print'),
            'original' => $resolver->get('term_original'),
            // Money leaves this endpoint as a JSON **integer** in cents
            // (owner decisions 2026-09-27/28: the API types its money, and
            // every monetary amount is cents). `settings.value` is a `text`
            // column, so these are stored as the text `'8000'` and projected
            // here — per key, via moneyInCents(), never as a blanket cast over
            // the response. The factors, the counts and the licence texts in
            // the same payload keep the stored string, because their typing is
            // exactly what tells a consumer which is which.
            'price_web' => $this->moneyInCents($resolver, 'price_web'),
            'price_print' => $this->moneyInCents($resolver, 'price_print'),
            'price_original' => $this->moneyInCents($resolver, 'price_original'),
            'base_price' => $this->moneyInCents($resolver, 'base_price'),
            'calc_base_price' => $this->moneyInCents($resolver, 'calc_base_price'),
            'calc_hourly_rate' => $this->moneyInCents($resolver, 'calc_hourly_rate'),
            'calc_images_per_hour' => $resolver->get('calc_images_per_hour'),
            'calc_outdoor_images_per_hour' => $resolver->get('calc_outdoor_images_per_hour'),
            'calc_flatrate_multiplier' => $resolver->get('calc_flatrate_multiplier'),
            'srp_base_price' => $this->moneyInCents($resolver, 'base_price'),
            'srp_setup_fee' => $this->moneyInCents($resolver, 'setup_fee'),
            'srp_privacy_fee' => $this->moneyInCents($resolver, 'privacy_fee'),
            'srp_extra_image_fee' => $this->moneyInCents($resolver, 'extra_image_fee'),
            'pricing_strategy' => $pricingStrategy,
            // Wire contract: `preset_id` is the `volume_presets.id` primary key
            // and is serialised as a JSON *number*. The frontend treats it as an
            // opaque identifier and only stringifies it for the `mode|preset`
            // group key; stringifying it here would break the numeric contract
            // documented in features/infrastructure/27-volume-licensing-presets.md.
            'volume_pricing' => $preset !== null ? [
                'preset_id' => (int) $preset->id,
                'preset_name' => $preset->name,
                'tiers' => $preset->tiers->map(fn ($tier) => [
                    'min_quantity' => (int) $tier->min_quantity,
                    'price_cents' => (int) $tier->price_cents,
                ])->values(),
            ] : null,
        ]);
    }

    /**
     * One money field of the licence-terms response, as a JSON integer.
     *
     * Applied per key, not across the response: a blanket cast would type the
     * dimensionless factors, the image counts and the licence texts as money
     * too, and the typing is the only thing on the wire that says which is
     * which.
     *
     * A missing setting stays `null` rather than becoming `0`. A `0` here is a
     * price — a free base price, a free quote — and silently manufacturing one
     * out of an absent row is a worse failure than a `null` a client has to
     * handle. The write path (`LICENSE_TERM_RULES`, `integer|min:500`) is what
     * keeps the column numeric, so no non-numeric value can reach this cast
     * through the application.
     */
    private function moneyInCents(SettingResolver $resolver, string $key): ?int
    {
        $value = $resolver->get($key);

        return $value === null ? null : (int) $value;
    }

    /**
     * R-01: Bankverbindung & Impressum — NUR authentifiziert (ClientOrdersView, Management).
     * Getrennt von den Lizenzbedingungen: Lizenztexte sind public-safe, Billing-/Firmendaten
     * sind sensibel und dürfen anonym nicht exponiert werden.
     */
    public function getBillingDetails(SettingResolver $resolver)
    {
        return response()->json([
            'bank_iban' => $resolver->get('bank_iban', ''),
            'bank_bic' => $resolver->get('bank_bic', ''),
            'bank_holder' => $resolver->get('bank_holder', ''),
            'company_street' => $resolver->get('company_street', ''),
            'company_zip' => $resolver->get('company_zip', ''),
            'company_city' => $resolver->get('company_city', ''),
            'company_country' => $resolver->get('company_country', ''),
            'company_email' => $resolver->get('company_email', 'hello@reisinger.pictures'),
        ]);
    }

    public function updateLicenseTerms(Request $request, SettingResolver $resolver)
    {
        // R-01 (naming/SRP): nur Lizenzbedingungen + Preisfaktoren. Bank-/Firmendaten gehören
        // in updateBillingDetails() (separater Endpunkt).
        // `srp_*` request keys are kept for frontend backward-compat but mapped to unprefixed,
        // brand-scoped settings keys on write (spec §3.2/§6).
        //
        // The request contract is derived from LICENSE_TERM_RULES so that every
        // settings key carries exactly one rule and every spelling that writes
        // it runs through that rule: a key only reachable through a legacy
        // spelling is not a request name of its own, and a legacy spelling
        // inherits the rule of the key it writes. Deriving both from one table
        // is what keeps `base_price` and `srp_base_price` from drifting apart
        // again — and the 422 is still reported under the name the client sent.
        $rules = self::LICENSE_TERM_RULES;
        foreach (self::LEGACY_ONLY_KEYS as $settingsKey) {
            unset($rules[$settingsKey]);
        }
        foreach (self::LEGACY_REQUEST_SPELLINGS as $spelling => $settingsKey) {
            $rules[$spelling] = self::LICENSE_TERM_RULES[$settingsKey];
        }

        $validated = $request->validate($rules);

        // Map legacy `srp_*` request keys to unprefixed brand-scoped settings keys.
        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            $resolver->set(self::LEGACY_REQUEST_SPELLINGS[$key] ?? $key, $value);
        }

        return response()->json(['success' => true]);
    }

    /**
     * R-01: Bankverbindung & Impressum speichern (nur Lizenzbedingungen-unabhängige Felder).
     */
    public function updateBillingDetails(Request $request, SettingResolver $resolver)
    {
        $validated = $request->validate([
            'bank_holder' => 'nullable|string',
            'bank_iban' => 'nullable|string',
            'bank_bic' => 'nullable|string',
            'company_street' => 'nullable|string',
            'company_zip' => 'nullable|string',
            'company_city' => 'nullable|string',
            'company_country' => 'nullable|string',
            'company_email' => 'nullable|string',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $resolver->set($key, $value);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * F3 (Step 2): read all configurable brand settings for every brand.
     *
     * Returns, per brand, the whitelist of editable fields, the config defaults,
     * the persisted DB overrides and the effective (merged) values.
     */
    public function getBrandSettings()
    {
        $service = app(BrandSettingsService::class);
        $brandIds = array_keys(config('brands', []));

        $brands = [];
        foreach ($brandIds as $id) {
            $brands[] = [
                'id' => $id,
                'editable_fields' => BrandSettingsService::OVERRIDABLE,
                'defaults' => $this->defaultFieldsForBrand($id),
                'overrides' => $service->overridesFor($id),
                'effective' => $this->effectiveFieldsForBrand($id),
            ];
        }

        return response()->json(['brands' => $brands]);
    }

    /**
     * F3 (Step 2): persist (or reset) a partial set of brand overrides.
     *
     * Only whitelisted keys sent in the payload are honored; a `null` value
     * resets that key back to the config default. Writes require the
     * `super_admin` middleware (defense-in-depth via the request too).
     */
    public function updateBrandSettings(string $brand, StoreBrandSettingsRequest $request)
    {
        $payload = [];
        foreach (BrandSettingsService::OVERRIDABLE as $key) {
            if ($request->has($key)) {
                $payload[$key] = $request->input($key);
            }
        }

        $service = app(BrandSettingsService::class);
        // Pass audit=false: we emit a single, user-attributed audit line below
        // and clear the brand-config cache manually (the service gates its own
        // cache-clear on the audit flag).
        $changed = $service->apply($brand, $payload, false);

        if ($changed !== []) {
            BrandRegistry::clearCache();
            Log::info('Brand settings updated', [
                'user' => $request->user()?->id,
                'brand' => $brand,
                'changed' => $changed,
            ]);
        }

        return response()->json([
            'success' => true,
            'effective' => $this->effectiveFieldsForBrand($brand),
        ]);
    }

    /**
     * Project the whitelisted fields from a BrandConfig (the effective result).
     */
    private function effectiveFieldsForBrand(string $brandId): array
    {
        $config = BrandRegistry::configForBrand($brandId);
        if (! $config instanceof BrandConfig) {
            return [];
        }

        return [
            'name' => $config->name,
            'portal_name' => $config->portalName,
            'impressum_url' => $config->impressumUrl,
            'primary_color' => $config->primaryColor,
            'secondary_color' => $config->secondaryColor,
            'frontend_url' => $config->frontendUrl,
            'from_address' => $config->fromAddress,
            'from_name' => $config->fromName,
            'accounting_email' => $config->accountingEmail,
            'features' => $config->features,
        ];
    }

    /**
     * Project the whitelisted fields from the raw config default (no DB overlay).
     */
    private function defaultFieldsForBrand(string $brandId): array
    {
        $data = config("brands.$brandId", []);

        return [
            'name' => $data['name'] ?? $brandId,
            'portal_name' => $data['portal_name'] ?? $brandId,
            'impressum_url' => $data['impressum_url'] ?? null,
            'primary_color' => $data['primary_color'] ?? '#1E5631',
            'secondary_color' => $data['secondary_color'] ?? '#A4B494',
            'frontend_url' => $data['frontend_url'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'accounting_email' => $data['accounting_email'] ?? null,
            'features' => $data['features'] ?? [],
        ];
    }
}
