<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\InvalidateWatermarkCacheJob;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\VolumePreset;
use App\Services\VolumePresetService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        return auth('api')->login($user);
    }

    private function superAdminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        return auth('api')->login($user);
    }

    private function clientToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        return auth('api')->login($user);
    }

    /**
     * Minimum payload `updateLicenseTerms()` accepts — the three `mult_*`
     * multipliers are `required` there. Mirrors the contract the shooting
     * calculator's own partial saves rely on.
     */
    private function validLicenseTermsPayload(array $overrides = []): array
    {
        return array_merge([
            'mult_commercial' => '2.0',
            'mult_unlimited' => '1.5',
            'mult_international' => '1.5',
        ], $overrides);
    }

    /**
     * The brand-scoped `settings.value` exactly as stored — the write leg's
     * whole truth, independent of how any response formats it.
     */
    private function storedSetting(string $key, string $brand = 'rp'): ?string
    {
        return Setting::where('key', $key)->where('brand', $brand)->value('value');
    }

    public function test_get_license_terms_is_public(): void
    {
        Setting::updateOrCreate(
            ['key' => 'base_price', 'brand' => 'rp'],
            ['value' => '500']
        );

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonStructure([
                'editorial', 'commercial', 'base_price', 'mult_commercial',
                'mult_unlimited', 'mult_international',
            ]);
    }

    public function test_get_license_terms_volume_pricing_uses_brand_default_preset(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'volume_licensing')
            ->assertJsonPath('volume_pricing.preset_name', 'Standard')
            ->assertJsonCount(3, 'volume_pricing.tiers')
            ->assertJsonPath('volume_pricing.tiers.1.min_quantity', 10);
    }

    public function test_get_license_terms_volume_pricing_resolves_gallery_preset(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $presetService = app(VolumePresetService::class);
        $custom = $presetService->create('Custom', [
            ['min_quantity' => 0, 'price_cents' => 7000],
        ]);
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $custom->id,
        ]);

        $this->getJson('/api/settings/license-terms?gallery_id='.$gallery->id)
            ->assertStatus(200)
            // Strict: `preset_id` is the numeric `volume_presets.id` primary key,
            // never a string. The frontend's opaque preset key relies on it.
            ->assertJsonPath('volume_pricing.preset_id', $custom->id, true)
            ->assertJsonPath('volume_pricing.tiers.0.price_cents', 7000);
    }

    /**
     * Regression: the frontend called `.trim()` on `volume_pricing.preset_id`,
     * which crashed the photo page ("preset_id?.trim is not a function") as soon
     * as the endpoint returned the bigint primary key as a JSON number. Pin the
     * wire *type* of that member, not just its value.
     */
    public function test_get_license_terms_serialises_preset_id_and_tiers_as_json_numbers(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $response = $this->getJson('/api/settings/license-terms')->assertStatus(200);
        $volumePricing = $response->json('volume_pricing');

        $this->assertIsArray($volumePricing);
        $this->assertIsInt($volumePricing['preset_id']);
        $this->assertSame(VolumePreset::forBrand('rp')?->id, $volumePricing['preset_id']);
        $this->assertIsString($volumePricing['preset_name']);
        $this->assertNotSame('', $volumePricing['preset_name']);
        $this->assertNotEmpty($volumePricing['tiers']);
        foreach ($volumePricing['tiers'] as $tier) {
            $this->assertIsInt($tier['min_quantity']);
            $this->assertIsInt($tier['price_cents']);
        }
    }

    public function test_get_license_terms_serialises_gallery_preset_id_as_json_number(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'volume_licensing']);

        $custom = app(VolumePresetService::class)->create('Custom Type', [
            ['min_quantity' => 0, 'price_cents' => 7000],
            ['min_quantity' => 5, 'price_cents' => 6000],
        ]);
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'licensing_mode' => 'volume_licensing',
            'volume_preset_id' => $custom->id,
        ]);

        $volumePricing = $this->getJson('/api/settings/license-terms?gallery_id='.$gallery->id)
            ->assertStatus(200)
            ->assertJsonPath('volume_pricing.preset_name', 'Custom Type')
            ->json('volume_pricing');

        $this->assertIsInt($volumePricing['preset_id']);
        $this->assertSame((int) $custom->id, $volumePricing['preset_id']);
        $this->assertNotSame((string) $custom->id, $volumePricing['preset_id']);
    }

    public function test_get_license_terms_volume_pricing_null_for_scope(): void
    {
        Setting::updateOrCreate(['key' => 'pricing_strategy', 'brand' => 'rp'], ['value' => 'scope_licensing']);

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('pricing_strategy', 'scope_licensing')
            ->assertJsonPath('volume_pricing', null);
    }

    public function test_get_billing_details_requires_auth(): void
    {
        $this->getJson('/api/settings/billing-details')
            ->assertStatus(401);
    }

    public function test_authenticated_user_can_read_billing_details(): void
    {
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test GmbH']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT11 2222 3333 4444 5555']);

        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_holder', 'Test GmbH')
            ->assertJsonPath('bank_iban', 'AT11 2222 3333 4444 5555');
    }

    public function test_non_admin_cannot_update_license_terms(): void
    {
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'mult_commercial' => 2.0,
                'mult_unlimited' => 1.5,
                'mult_international' => 1.5,
            ])
            ->assertStatus(403);
    }

    public function test_non_super_admin_cannot_update_billing_details(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'Hacker GmbH',
            ])
            ->assertStatus(403);
    }

    public function test_super_admin_can_update_billing_details(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_holder' => 'Berechtigt GmbH',
                'bank_iban' => 'AT99 8888 7777 6666 5555',
                'company_city' => 'Wien',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'bank_holder', 'value' => 'Berechtigt GmbH']);
    }

    public function test_admin_can_read_system_info(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->assertJsonStructure([
                'laravel_build_time', 'php_version', 'laravel_version', 'db_version',
            ]);
    }

    public function test_system_info_build_time_refreshes_after_cache_clear(): void
    {
        // Regression (2026-08-17): the build time was cached with
        // rememberForever and had NO invalidation path — the value froze at the
        // first request and survived rclone syncs and container restarts.
        // The reset now happens via `php artisan cache:clear` in the backend
        // command block (every container start).
        //
        // The stale timestamp is captured ONCE, before the request that serves it.
        // Evaluating `now()` a second time after the round trip compared two
        // different wall-clock readings: whenever the request straddled a second
        // boundary the assertion saw a 1s difference and failed intermittently in
        // the full suite. The product code is correct; the test was racy.
        $stale = now()->subDays(30)->getTimestamp();
        Cache::put('laravel_build_time', $stale);
        $token = $this->adminToken();

        // Cache hit: the stale value is served while the key exists.
        $cached = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->json('laravel_build_time');
        $this->assertSame($stale, strtotime($cached));

        // Container start runs `php artisan cache:clear` — same effect here.
        Cache::forget('laravel_build_time');

        // Recomputed from the newest PHP file mtime — no longer frozen.
        $fresh = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/system')
            ->assertStatus(200)
            ->json('laravel_build_time');
        $this->assertGreaterThan($stale, strtotime($fresh));
    }

    public function test_update_license_terms_requires_mult_fields(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'base_price' => 1000,
            ])
            ->assertStatus(422);
    }

    /**
     * Regression: the three resolution prices were neither validated on write
     * nor served on read, so `$request->validate()` dropped them from
     * `$validated`, `SettingResolver::set()` never saw them, and the refetch
     * that follows a save had nothing to hydrate the card from — a price typed
     * into the licence settings snapped back to its default.
     *
     * Unit contract: the card edits euros and submits cents
     * (`Math.round(euros * 100)`), and the `settings` table stores cents —
     * `DatabaseSeeder` seeds `'price_web' => '7500'` / `'price_print' =>
     * '14500'` / `'price_original' => '45000'` directly above its comment
     * "Per-image license base prices are stored in cents" (which annotates
     * `base_price => '8000'`, validated as `integer|min:500`). The read
     * therefore has to hand the stored cents back verbatim, because
     * `pricingLogic.getRequiredTerm()` parseInts them and
     * `LicenseSelectorModal` divides the resulting upgrade price by 100 for
     * display.
     *
     * The three values are deliberately *not* round hundreds, so a stray ×100
     * or ÷100 on either leg fails this test instead of hiding behind a value
     * that is symmetric under both.
     */
    public function test_update_license_terms_round_trips_the_resolution_prices_in_cents(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'price_web' => 12345,
                'price_print' => 24901,
                'price_original' => 89999,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Write leg: the raw `settings.value` string *is* the stored cents.
        $this->assertDatabaseHas('settings', ['key' => 'price_web', 'brand' => 'rp', 'value' => '12345']);
        $this->assertDatabaseHas('settings', ['key' => 'price_print', 'brand' => 'rp', 'value' => '24901']);
        $this->assertDatabaseHas('settings', ['key' => 'price_original', 'brand' => 'rp', 'value' => '89999']);

        // Read leg: the public endpoint returns the same cents, as JSON
        // integers (owner decision 2026-09-27: the API types its money).
        $terms = $this->getJson('/api/settings/license-terms')->assertStatus(200);
        $this->assertSame(12345, $terms->json('price_web'));
        $this->assertSame(24901, $terms->json('price_print'));
        $this->assertSame(89999, $terms->json('price_original'));

        // Round trip in the card's own unit: the served cents hydrate back into
        // the euros that were typed in (123.45 / 249.01 / 899.99) — the same
        // `/100` the licence card performs.
        $this->assertSame(123.45, $terms->json('price_web') / 100);
        $this->assertSame(249.01, $terms->json('price_print') / 100);
        $this->assertSame(899.99, $terms->json('price_original') / 100);
    }

    /**
     * The card hydrates `price_*` from the public endpoint, and
     * `pricingLogic.getRequiredTerm()` throws "Kritischer Systemfehler" when a
     * price factor is missing. Pin both the presence and the JSON *type*.
     *
     * The type used to be pinned as a **string**, which was true while every
     * value on this endpoint was the raw `settings.value` text. Owner decision
     * 2026-09-27 inverted it: money leaves the API as a JSON integer, so that
     * a client cannot mistake a cent amount for anything else. The non-money
     * members of the same response stay text — that is what keeps them
     * distinguishable — and are pinned in `ShootingCalculatorSettingsTest`.
     */
    public function test_get_license_terms_exposes_the_resolution_prices_as_cent_integers(): void
    {
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '7500']);
        Setting::updateOrCreate(['key' => 'price_print', 'brand' => 'rp'], ['value' => '14500']);
        Setting::updateOrCreate(['key' => 'price_original', 'brand' => 'rp'], ['value' => '45000']);

        $terms = $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonStructure(['price_web', 'price_print', 'price_original'])
            ->assertJsonPath('price_web', 7500)
            ->assertJsonPath('price_print', 14500)
            ->assertJsonPath('price_original', 45000);

        $this->assertIsInt($terms->json('price_web'));
        $this->assertIsInt($terms->json('price_print'));
        $this->assertIsInt($terms->json('price_original'));
    }

    /**
     * The three keys only ever reach the table through `DatabaseSeeder`
     * (`insertOrIgnore`, per brand), so a row that predates them simply has no
     * `price_*` value. Reads must degrade to `null` and a partial save — the
     * shooting calculator's own save carries only `calc_*` plus the
     * multipliers — must still succeed instead of 422-ing the whole endpoint.
     */
    public function test_resolution_prices_absent_from_the_table_do_not_break_reads_or_partial_saves(): void
    {
        $this->assertDatabaseMissing('settings', ['key' => 'price_web']);

        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'calc_base_price' => 7500,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('settings', ['key' => 'calc_base_price', 'brand' => 'rp', 'value' => '7500']);

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('price_web', null)
            ->assertJsonPath('price_print', null)
            ->assertJsonPath('price_original', null);
    }

    /**
     * Cents are whole numbers, so a fractional amount is the signal that a
     * caller sent euros instead — reject it rather than store a price that is
     * off by two decimal orders of magnitude. `min:0` matches the card's
     * `min="0"` input, so a deliberately free tier is still saveable.
     */
    #[DataProvider('invalidResolutionPriceProvider')]
    public function test_update_license_terms_rejects_invalid_resolution_prices(string $key, mixed $value): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                $key => $value,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$key]);
    }

    public static function invalidResolutionPriceProvider(): array
    {
        return [
            'price_web dezimal (euros statt cents)' => ['price_web', '75.5'],
            'price_print negativ' => ['price_print', -1],
            'price_original nicht-numerisch' => ['price_original', 'free'],
        ];
    }

    /**
     * `ConvertEmptyStringsToNull` plus the `nullable` rules make an empty
     * price a "leave it alone" signal, exactly like every other key on this
     * endpoint. Guard the direction that would cost money: a save that omits or
     * blanks a price must never wipe the stored one.
     */
    public function test_an_empty_resolution_price_keeps_the_stored_value(): void
    {
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '7500']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'price_web' => '',
                'price_print' => 24901,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('settings', ['key' => 'price_web', 'brand' => 'rp', 'value' => '7500']);
        $this->assertDatabaseHas('settings', ['key' => 'price_print', 'brand' => 'rp', 'value' => '24901']);
    }

    // ------------------------------------------------------------------
    // Rule belongs to the settings key, not to the request spelling
    // (features/infrastructure/28-settings-key-meaning.md)
    //
    // `base_price` and `srp_base_price` are two spellings of ONE settings key.
    // The mapping is deliberate backwards compatibility, not a bug — but while
    // the rule hung off the request term, the legacy name got a weaker rule
    // (`nullable|numeric|min:0`) than the canonical one (`integer|min:500`).
    // A client could therefore pick the weak rule by picking the old name, and
    // the stored value was indistinguishable from a validated one.
    //
    // The negative direction is the whole point: a round-trip test proves only
    // that a value *arrived*, which is exactly why this went unnoticed.
    // ------------------------------------------------------------------

    /**
     * The legacy spelling must be held to the canonical rule, so every value
     * the canonical name rejects is rejected under the legacy name too — and
     * nothing lands in between.
     *
     * Before the fix all four rows below returned 200 and wrote to `base_price`
     * (`1` → `'1'`, `1.5` → `'1.5'`), because the legacy key was validated as
     * `nullable|numeric|min:0` while the canonical key rejected the identical
     * input on both the integer rule and the minimum.
     */
    #[DataProvider('basePriceRejectedByTheCanonicalRuleProvider')]
    public function test_legacy_srp_base_price_is_rejected_wherever_the_canonical_name_is(mixed $value): void
    {
        Setting::updateOrCreate(['key' => 'base_price', 'brand' => 'rp'], ['value' => '8000']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'srp_base_price' => $value,
            ]))
            // Reported under the name the client actually sent.
            ->assertStatus(422)
            ->assertJsonValidationErrors(['srp_base_price']);

        // A rejected payload must not leave a value behind — that is the part
        // that made the hole invisible: the row looked like any other write.
        $this->assertSame('8000', $this->storedSetting('base_price'));
    }

    public static function basePriceRejectedByTheCanonicalRuleProvider(): array
    {
        return [
            'unter dem Mindestwert' => [1],
            'dezimal (cent-Betrag)' => ['1.5'],
            'dezimal über dem Mindestwert' => ['500.5'],
            'dezimal mit Nachkommastelle als Float' => [1.5],
            'negativ' => [-500],
            'knapp unter dem Mindestwert' => [499],
        ];
    }

    /**
     * The other direction of the same guard: the canonical name still rejects
     * the identical input, so the fix closed the legacy hole instead of moving
     * it to the other spelling.
     */
    #[DataProvider('basePriceRejectedByTheCanonicalRuleProvider')]
    public function test_canonical_base_price_still_rejects_the_same_values(mixed $value): void
    {
        Setting::updateOrCreate(['key' => 'base_price', 'brand' => 'rp'], ['value' => '8000']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'base_price' => $value,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['base_price']);

        $this->assertSame('8000', $this->storedSetting('base_price'));
    }

    /**
     * Backwards compatibility has to survive the fix: a valid value under the
     * legacy name is still accepted, still writes the same settings key, and
     * still produces byte-identical storage to the canonical spelling — the
     * difference is the name on the wire, nothing else.
     */
    #[DataProvider('acceptedBasePriceProvider')]
    public function test_legacy_and_canonical_base_price_produce_identical_storage(mixed $value): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'base_price' => $value,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);
        $viaCanonicalName = $this->storedSetting('base_price');

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'srp_base_price' => $value,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);
        $viaLegacyName = $this->storedSetting('base_price');

        $this->assertNotNull($viaCanonicalName);
        $this->assertSame($viaCanonicalName, $viaLegacyName);
        $this->assertSame((string) $value, $viaLegacyName);

        // One row, two names in the response — the response shape is unchanged
        // and both members read that single row. Money goes out as a JSON
        // integer, so the response member is the stored cents as a number, not
        // the stored text.
        $this->assertSame(1, Setting::where('key', 'base_price')->where('brand', 'rp')->count());
        $terms = $this->getJson('/api/settings/license-terms')->assertStatus(200);
        $this->assertSame((int) $viaLegacyName, $terms->json('base_price'));
        $this->assertSame((int) $viaLegacyName, $terms->json('srp_base_price'));
    }

    public static function acceptedBasePriceProvider(): array
    {
        return [
            'exakt am Mindestwert' => [500],
            'Seed-Default' => [8000],
            'als Text gesendeter Cent-Betrag' => ['12345'],
        ];
    }

    /**
     * The mapping itself is the compatibility contract and must not be "fixed"
     * away: all four legacy spellings still reach their unprefixed target.
     */
    public function test_all_four_legacy_spellings_still_write_their_mapped_key(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                'srp_base_price' => 8000,
                'srp_setup_fee' => 5000,
                'srp_privacy_fee' => 20000,
                'srp_extra_image_fee' => 1500,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame('8000', $this->storedSetting('base_price'));
        $this->assertSame('5000', $this->storedSetting('setup_fee'));
        $this->assertSame('20000', $this->storedSetting('privacy_fee'));
        $this->assertSame('1500', $this->storedSetting('extra_image_fee'));

        // …and no unprefixed `srp_*` row was created alongside them.
        $this->assertSame(0, Setting::where('key', 'like', 'srp_%')->count());
    }

    /**
     * The three SRP fees are cent amounts (`Math.round(euros * 100)` on the
     * card, 5000 / 20000 / 1500 in the seeder, read back as `Number(v)/100`),
     * so they carry the same `integer` unit guard as `price_*`. `numeric`
     * admitted a fractional cent such as `'5000.5'` — a value no client can
     * produce and no consumer can represent, stored indistinguishably from a
     * validated amount. `min:0` is unchanged, so a free setup fee stays legal.
     */
    #[DataProvider('legacyFeeProvider')]
    public function test_srp_fees_reject_a_fractional_cent(string $spelling, string $settingsKey): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                $spelling => '5000.5',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$spelling]);

        $this->assertNull($this->storedSetting($settingsKey));

        // The whole value stays saveable — only the fraction is refused.
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                $spelling => 5000,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame('5000', $this->storedSetting($settingsKey));
    }

    /**
     * The three fee keys are write targets, not request names. Deriving the
     * rules from the key table must not promote them to an additional spelling
     * of the API — the legacy name stays their only entry point.
     */
    #[DataProvider('legacyFeeProvider')]
    public function test_fee_keys_are_not_reachable_under_their_unprefixed_name(string $spelling, string $settingsKey): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validLicenseTermsPayload([
                $settingsKey => 5000,
            ]))
            ->assertStatus(200);

        $this->assertNull($this->storedSetting($settingsKey));
    }

    public static function legacyFeeProvider(): array
    {
        return [
            'setup_fee' => ['srp_setup_fee', 'setup_fee'],
            'privacy_fee' => ['srp_privacy_fee', 'privacy_fee'],
            'extra_image_fee' => ['srp_extra_image_fee', 'extra_image_fee'],
        ];
    }

    public function test_watermark_update_dispatches_retryable_cache_invalidation_job(): void
    {
        Queue::fake();
        $this->useTemporaryStorageDisk('photos');
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/management/settings/watermark', [
                'opacity' => 0.5,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        Queue::assertPushed(InvalidateWatermarkCacheJob::class, function (InvalidateWatermarkCacheJob $job): bool {
            return $job->tries === 3
                && $job->backoff === [30, 60, 120];
        });
    }

    public function test_watermark_cache_cleanup_throws_and_records_terminal_failure(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('directories')
            ->once()
            ->andReturn(['550e8400-e29b-41d4-a716-446655440000']);
        $disk->shouldReceive('deleteDirectory')
            ->twice()
            ->andReturn(false);
        Storage::set('photos', $disk);
        Log::spy();

        $job = new InvalidateWatermarkCacheJob;

        try {
            $job->handle();
            $this->fail('Expected watermark cache deletion to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Failed to delete 2 watermark cache directories',
                $exception->getMessage(),
            );
            $job->failed($exception);
        }

        Log::shouldHaveReceived('error')
            ->twice()
            ->withArgs(function (string $message, array $context): bool {
                return in_array($message, [
                    'Automated cleanup: watermark cache deletion failures',
                    'Queue job failed',
                ], true)
                    && ($context['failed_count'] ?? null) === 2
                    && count($context['failures'] ?? []) === 2;
            });
    }

    public function test_get_watermark_requires_management_role(): void
    {
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/settings/watermark')
            ->assertStatus(403);
    }
}
