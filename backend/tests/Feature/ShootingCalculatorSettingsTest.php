<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ShootingCalculatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The settings this spec reads are the *seeder's*, not a migration's.
     *
     * `RefreshDatabase` runs the migrations but never the seeder, so before
     * this the fixture inherited whatever the migration chain happened to
     * insert: `V004` seeds `base_price => '35.00'` (35 cents, the value the
     * `min:500` rule rejects) and `V016` seeds the two calculator amounts as
     * euros. `test_get_license_terms_returns_defaults_when_settings_missing`
     * asserted that inherited state and called it "defaults" — which is how a
     * migration-seeded euro row survived a test suite that had already decided
     * the field was cents. It is the same trap the `insertOrIgnore` fix closed
     * (DatabaseSeederSettingsAuthorityTest): do not let a fixture fall back to
     * migration-seeded values.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Config::set('admin.email', 'calc-settings-admin@example.test');
        Config::set('admin.password', 'calc-settings-password-for-tests');

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Erzeugt einen Admin-User mit Rolle und gibt einen gültigen JWT zurück.
     */
    private function adminToken(): string
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::ADMIN->value])
        );

        return auth('api')->login($admin);
    }

    /**
     * Erzeugt einen Super-Admin-User mit Rolle und gibt einen gültigen JWT zurück.
     * Benötigt für billing-details-WRITE (R-01: super_admin-gesichert).
     */
    private function superAdminToken(): string
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );

        return auth('api')->login($superAdmin);
    }

    /**
     * Gültiges calc_*-Payload. Setzt immer die required mult_*-Felder,
     * damit calc_*-spezifische Validierungsregeln nicht durch fehlende
     * required-Felder überlagert werden.
     */
    private function validCalcPayload(array $overrides = []): array
    {
        return array_merge([
            'mult_commercial' => '2.0',
            'mult_unlimited' => '1.5',
            'mult_international' => '1.5',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // GET /api/settings/license-terms — Defaults & gespeicherte Werte
    // ------------------------------------------------------------------

    /**
     * The response of the public endpoint (R-01: licence texts + price factors,
     * no bank data) in its two typing dimensions at once.
     *
     * Money leaves this endpoint as a JSON **integer** in cents — including
     * whole-euro amounts, which is why `calc_base_price` is 5000 and not 50
     * (owner decisions 2026-09-27/28). Everything that is not money keeps the
     * stored `settings.value` text, because that typing is the only thing on
     * the wire that distinguishes a cent amount from a factor or a count.
     */
    public function test_get_license_terms_serves_money_as_cent_integers_and_everything_else_as_text(): void
    {
        $response = $this->getJson('/api/settings/license-terms');

        $response->assertStatus(200)
            // Money: integer cents.
            ->assertJsonPath('base_price', 8000)
            ->assertJsonPath('calc_base_price', 5000)
            ->assertJsonPath('calc_hourly_rate', 8000)
            ->assertJsonPath('price_web', 7500)
            ->assertJsonPath('price_print', 14500)
            ->assertJsonPath('price_original', 45000)
            // The legacy `srp_*` spellings of the same four money keys.
            ->assertJsonPath('srp_base_price', 8000)
            ->assertJsonPath('srp_setup_fee', 5000)
            ->assertJsonPath('srp_privacy_fee', 20000)
            ->assertJsonPath('srp_extra_image_fee', 1500)
            // Not money, and explicitly not swept along by the per-key cast:
            // counts stay text, dimensionless factors stay text.
            ->assertJsonPath('calc_images_per_hour', '6')
            ->assertJsonPath('calc_outdoor_images_per_hour', '8')
            ->assertJsonPath('calc_flatrate_multiplier', '1.2')
            ->assertJsonPath('mult_commercial', '2.0')
            ->assertJsonPath('mult_unlimited', '1.5')
            ->assertJsonPath('mult_international', '1.5');

        // `assertJsonPath` compares with `assertSame`, so the assertions above
        // already separate `8000` from `'8000'`. Asserted on the wire as well,
        // because the failure this guards against is a *JSON type* change that
        // a client reads as a coercion and swallows.
        $body = $response->getContent();
        $this->assertStringContainsString('"calc_base_price":5000', $body);
        $this->assertStringContainsString('"calc_hourly_rate":8000', $body);
        $this->assertStringContainsString('"base_price":8000', $body);
        $this->assertStringContainsString('"calc_images_per_hour":"6"', $body);
        $this->assertStringContainsString('"calc_flatrate_multiplier":"1.2"', $body);
    }

    /**
     * A money field with no row must stay `null`, not become `0`.
     *
     * The per-key cast would turn a missing setting into a price of zero, and
     * a zero base price is a free quote rather than an absent one — a failure
     * that only shows up on an invoice. `calc_outdoor_images_per_hour` is a
     * count and needs no row; the money fields are checked here on a brand
     * that was never seeded.
     */
    public function test_absent_money_fields_stay_null_instead_of_becoming_zero(): void
    {
        Setting::query()->delete();

        $response = $this->getJson('/api/settings/license-terms')->assertStatus(200);

        $response->assertJsonPath('calc_base_price', null)
            ->assertJsonPath('calc_hourly_rate', null)
            ->assertJsonPath('base_price', null)
            ->assertJsonPath('srp_base_price', null)
            ->assertJsonPath('srp_setup_fee', null)
            ->assertJsonPath('srp_privacy_fee', null)
            ->assertJsonPath('srp_extra_image_fee', null)
            ->assertJsonPath('price_web', null)
            ->assertJsonPath('calc_images_per_hour', null)
            ->assertJsonPath('calc_flatrate_multiplier', null);
    }

    public function test_get_license_terms_returns_persisted_values_after_put(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => 7500,
                'calc_hourly_rate' => 15000,
                'calc_images_per_hour' => '10',
            ]))
            ->assertStatus(200);

        // Folge-GET liefert die gespeicherten Werte (license-terms ist öffentlich, s. R-01)
        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('calc_base_price', 7500)
            ->assertJsonPath('calc_hourly_rate', 15000)
            // The count keeps its text typing through the same request.
            ->assertJsonPath('calc_images_per_hour', '10');
    }

    // ------------------------------------------------------------------
    // R-01 (security/naming): Lizenztexte sind public-safe, Bank-/Firmendaten liegen
    // im separaten, auth-geschützten Endpunkt /settings/billing-details.
    // ------------------------------------------------------------------

    public function test_license_terms_is_public_and_omits_billing_data(): void
    {
        // Sensible Werte persistieren, damit ein Leak erkannt würde (nicht nur leere Defaults).
        // Settings werden brand-scoped gelesen (resolver scope nach brand='rp' im Default-B2B-Kontext),
        // daher wird hier explizit die B2B-Brand mitgegeben.
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT99 9999 9999 9999 9999']);
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Max Mustermann']);
        Setting::updateOrCreate(['key' => 'company_email', 'brand' => 'rp'], ['value' => 'finance@reisinger.pictures']);
        Setting::updateOrCreate(['key' => 'company_city', 'brand' => 'rp'], ['value' => 'Wien']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'calc_images_per_hour', 'brand' => 'rp'], ['value' => '6']);

        // Vollständig anonymer Aufruf (kein Authorization-Header) — license-terms ist öffentlich.
        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonMissingPath('bank_iban')
            ->assertJsonMissingPath('bank_bic')
            ->assertJsonMissingPath('bank_holder')
            ->assertJsonMissingPath('company_street')
            ->assertJsonMissingPath('company_zip')
            ->assertJsonMissingPath('company_city')
            ->assertJsonMissingPath('company_country')
            ->assertJsonMissingPath('company_email')
            // Regression-Guard: legitime öffentliche Felder bleiben verfügbar (Gallery-Flow).
            ->assertJsonStructure([
                'editorial', 'commercial', '1_year', 'unlimited',
                'mult_commercial', 'mult_unlimited', 'mult_international',
                'base_price', 'calc_base_price', 'calc_hourly_rate', 'calc_images_per_hour',
            ])
            ->assertJsonPath('mult_commercial', '2.0')
            ->assertJsonPath('calc_images_per_hour', '6');
    }

    // ------------------------------------------------------------------
    // Der Einheitenwechsel der beiden `calc_*`-Geldfelder (Owner-Entscheidung
    // 2026-09-28): Euro → Cent, Speicher und API, ohne Ausnahme.
    // ------------------------------------------------------------------

    /**
     * The round trip the `calc_*` fields were missing.
     *
     * A round trip on its own proves only that *a* value arrived — which is how
     * the euro contract survived in the first place (see the class docblock on
     * `SettingsControllerTest.php` and the regression it guards). What makes
     * this meaningful is the **unit marker**: the amounts are not round
     * hundreds, so 75.00 € hydrates to 75, submits back as 7500, and any
     * surviving `× 100` or `÷ 100` on either leg lands on a different number
     * that this test can see. `50` would pass under both units.
     *
     * The card's own arithmetic is applied literally: `mapApiToForm` divides
     * by 100, `mapFormToApi` multiplies by 100 and rounds.
     */
    public function test_calc_money_fields_round_trip_in_cents_at_the_value_the_card_wrote(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => 7500,
                'calc_hourly_rate' => 15000,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $terms = $this->getJson('/api/settings/license-terms')->assertStatus(200);

        // What the card would render in the input, and what it would submit.
        // PHP's `/` returns an int when both operands are ints and divide
        // evenly, so the rendered amount is 75 / 150 — 75,00 € and 150,00 €,
        // exactly what the `step="0.01"` number input would show.
        $basePriceEuro = $terms->json('calc_base_price') / 100;
        $hourlyRateEuro = $terms->json('calc_hourly_rate') / 100;
        $this->assertSame(75, $basePriceEuro);
        $this->assertSame(150, $hourlyRateEuro);
        // `mapFormToApi` sends `Math.round(x * 100)`, an integer.
        $this->assertSame(7500, (int) round($basePriceEuro * 100));
        $this->assertSame(15000, (int) round($hourlyRateEuro * 100));

        // The stored value is the cent amount, and the euro amount is not
        // anywhere in it: 7500 cents, never '75' or '750000'.
        $this->assertSame('7500', Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame('15000', Setting::where('key', 'calc_hourly_rate')->value('value'));

        // Submitting the card's own division-and-remultiplication back must be
        // a no-op on the stored value.
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => round($basePriceEuro * 100),
                'calc_hourly_rate' => round($hourlyRateEuro * 100),
            ]))
            ->assertStatus(200);

        $this->assertSame('7500', Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame('15000', Setting::where('key', 'calc_hourly_rate')->value('value'));
    }

    /**
     * The seeded values are the ones the endpoint serves, and they are cents.
     *
     * Pinned through the response rather than through the column, because the
     * column is what the fixture would have to be trusted for: the value that
     * reached a client is the one that has to carry the unit.
     */
    public function test_seeded_calc_amounts_are_served_as_cents(): void
    {
        $this->assertSame('5000', Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame('8000', Setting::where('key', 'calc_hourly_rate')->value('value'));

        $this->getJson('/api/settings/license-terms')
            ->assertStatus(200)
            ->assertJsonPath('calc_base_price', 5000)
            ->assertJsonPath('calc_hourly_rate', 8000);
    }

    public function test_billing_details_rejects_anonymous_request(): void
    {
        // Billing-/Impressum-Daten sind sensibel → anonym = 401 (kein Leak).
        $this->getJson('/api/settings/billing-details')->assertStatus(401);
    }

    public function test_billing_details_returns_data_when_authenticated(): void
    {
        // GET bleibt auth:api (Klienten brauchen Bankdaten für "Kauf auf Rechnung").
        Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT99 9999 9999 9999 9999']);
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Max Mustermann']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/settings/billing-details')
            ->assertStatus(200)
            ->assertJsonPath('bank_iban', 'AT99 9999 9999 9999 9999')
            ->assertJsonPath('bank_holder', 'Max Mustermann');
    }

    public function test_billing_details_update_requires_super_admin(): void
    {
        // R-01: WRITE ist super_admin-gesichert. Ein regulärer Admin -> 403.
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_iban' => 'AT11 2222 3333 4444 5555',
            ])
            ->assertStatus(403);
    }

    public function test_billing_details_update_persists_for_super_admin(): void
    {
        $token = $this->superAdminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/billing-details', [
                'bank_iban' => 'AT11 2222 3333 4444 5555',
            ])
            ->assertStatus(200);

        $this->assertSame('AT11 2222 3333 4444 5555', Setting::where('key', 'bank_iban')->value('value'));
    }

    // ------------------------------------------------------------------
    // PUT /api/management/settings/license-terms — valide Updates
    // ------------------------------------------------------------------

    public function test_update_accepts_valid_calc_values_and_persists(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => 6050,
                'calc_hourly_rate' => 12000,
                'calc_images_per_hour' => '8',
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Persistenz in DB prüfen (Werte werden als String gespeichert)
        $this->assertSame('6050', Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame('12000', Setting::where('key', 'calc_hourly_rate')->value('value'));
        $this->assertSame('8', Setting::where('key', 'calc_images_per_hour')->value('value'));
    }

    /**
     * The decimal contract is gone, on purpose, and this is the test that says so.
     *
     * The removed `test_update_accepts_decimal_for_numeric_calc_fields` pinned
     * `'49.99'` / `'99.5'` as valid for these two keys — true while they were
     * euros, and a *sub-cent* amount under the cent contract. In cents a
     * fractional value can only mean a caller sent euros, and storing it would
     * be off by two decimal orders of magnitude. `integer` is the unit guard,
     * and it is exactly what `base_price` has carried all along; the two
     * `calc_*` fields are now held to the same rule as their sibling in the
     * same form.
     *
     * So this is not a lost capability but a closed door, and the rejection is
     * asserted with the stored value unchanged — a rejected payload that still
     * wrote a row is what made the original hole invisible.
     */
    #[DataProvider('calcAmountsRejectedByTheCentRuleProvider')]
    public function test_calc_money_fields_reject_sub_cent_and_sub_minimum_amounts(mixed $value, string $key): void
    {
        $token = $this->adminToken();
        $before = Setting::where('key', $key)->value('value');

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([$key => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$key]);

        $this->assertSame($before, Setting::where('key', $key)->value('value'));
    }

    public static function calcAmountsRejectedByTheCentRuleProvider(): array
    {
        return [
            // The two values the removed decimal test pinned, now sub-cent.
            'Dezimal (früher gültig)' => ['49.99', 'calc_base_price'],
            'Dezimal als Float (früher gültig)' => [99.5, 'calc_hourly_rate'],
            'Dezimal unter dem Mindestwert' => ['1.5', 'calc_base_price'],
            'Dezimal knapp über dem Mindestwert' => ['500.5', 'calc_hourly_rate'],
            'Euro-Betrag mit Nachkommastelle' => ['75.5', 'calc_base_price'],
            'unter dem Mindestwert' => [499, 'calc_base_price'],
            'knapp unter dem Mindestwert' => [100, 'calc_hourly_rate'],
            'negativ' => [-500, 'calc_base_price'],
            'nicht-numerisch' => ['abc', 'calc_base_price'],
        ];
    }

    /**
     * The other direction, so the tightened rule is a unit change and not a new
     * restriction: a plain cent amount at or above the minimum is accepted, in
     * both the numeric and the text spelling a client can send.
     */
    #[DataProvider('acceptedCalcAmountProvider')]
    public function test_calc_money_fields_accept_integer_cents_at_or_above_the_minimum(mixed $value): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_base_price' => $value,
                'calc_hourly_rate' => $value,
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame((string) $value, Setting::where('key', 'calc_base_price')->value('value'));
        $this->assertSame((string) $value, Setting::where('key', 'calc_hourly_rate')->value('value'));
    }

    public static function acceptedCalcAmountProvider(): array
    {
        return [
            'exakt am Mindestwert' => [500],
            'als Zahl' => [7500],
            'als Text' => ['12345'],
            'Seed-Default' => [5000],
        ];
    }

    public function test_update_persists_outdoor_images_per_hour(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload([
                'calc_outdoor_images_per_hour' => '10',
            ]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame('10', Setting::where('key', 'calc_outdoor_images_per_hour')->value('value'));
    }

    /**
     * The data migration behind the unit change, exercised on the row state the
     * previous migrations leave behind.
     *
     * `V016` seeds the two amounts as euros and `V045` supersedes that; running
     * the migration's `up()` here is what proves the backfill is the × 100 it
     * claims to be rather than a value carried over by the seeder — the fixture
     * seeds, so the row is in cents when this starts, and the test rewrites it
     * into the euro state first.
     */
    public function test_migration_multiplies_the_euro_amounts_by_one_hundred(): void
    {
        $migration = require database_path('migrations/V045__calculator_money_fields_to_cents.php');

        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '50']);
        Setting::updateOrCreate(['key' => 'calc_hourly_rate', 'brand' => 'rp'], ['value' => '80']);

        $migration->up();

        $this->assertSame('5000', Setting::where('key', 'calc_base_price')->where('brand', 'rp')->value('value'));
        $this->assertSame('8000', Setting::where('key', 'calc_hourly_rate')->where('brand', 'rp')->value('value'));

        // The counts and the factor are not money and must be untouched.
        $this->assertSame('6', Setting::where('key', 'calc_images_per_hour')->where('brand', 'rp')->value('value'));
        $this->assertSame('1.2', Setting::where('key', 'calc_flatrate_multiplier')->where('brand', 'rp')->value('value'));
    }

    /**
     * A value that is not a whole number of euros rounds to whole cents.
     *
     * The two fields accepted a decimal amount as euros, so a production row
     * can hold `49.99`; after the conversion that has to be 4999 cents, not
     * 4999.0 and not 5000.
     */
    public function test_migration_rounds_a_fractional_euro_amount_to_whole_cents(): void
    {
        $migration = require database_path('migrations/V045__calculator_money_fields_to_cents.php');

        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '49.99']);
        Setting::updateOrCreate(['key' => 'calc_hourly_rate', 'brand' => 'rp'], ['value' => '99.5']);

        $migration->up();

        $this->assertSame('4999', Setting::where('key', 'calc_base_price')->where('brand', 'rp')->value('value'));
        $this->assertSame('9950', Setting::where('key', 'calc_hourly_rate')->where('brand', 'rp')->value('value'));
    }

    /**
     * A row that already holds a cent-conform amount must stop the migration.
     *
     * This is the case the backfill cannot resolve on its own: `5000` is a valid
     * euro amount in theory and a valid cent amount in practice, and a blind
     * `× 100` would turn it into `500000` — 5000,00 € — without any error
     * anywhere. The migration therefore refuses to run rather than guess, and
     * names the row so the operator can decide.
     */
    public function test_migration_refuses_a_row_that_is_already_cent_conform(): void
    {
        $migration = require database_path('migrations/V045__calculator_money_fields_to_cents.php');

        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '5000']);
        Setting::updateOrCreate(['key' => 'calc_hourly_rate', 'brand' => 'rp'], ['value' => '80']);

        try {
            $migration->up();
            $this->fail('The migration accepted an already cent-conform row instead of refusing to guess.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('calc_base_price', $e->getMessage());
            $this->assertStringContainsString('5000', $e->getMessage());
        }

        // Nothing was written: the refusal happens before any update, and the
        // sibling row that could have been converted safely is left alone too.
        $this->assertSame('5000', Setting::where('key', 'calc_base_price')->where('brand', 'rp')->value('value'));
        $this->assertSame('80', Setting::where('key', 'calc_hourly_rate')->where('brand', 'rp')->value('value'));
    }

    /**
     * A value that is not a number must not be laundered into a price.
     *
     * `(int) 'abc'` is 0, and a 0 € base price is a free quote. The migration
     * refuses such a row for the same reason it refuses a cent-conform one.
     */
    public function test_migration_refuses_a_row_that_is_not_a_number(): void
    {
        $migration = require database_path('migrations/V045__calculator_money_fields_to_cents.php');

        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => 'on request']);

        $this->expectException(RuntimeException::class);
        $migration->up();
    }

    public function test_migration_converts_outdoor_multiplier_preserving_price(): void
    {
        $migration = require database_path('migrations/V028__calc_outdoor_images_per_hour.php');

        Setting::updateOrCreate(['key' => 'calc_outdoor_multiplier', 'brand' => 'rp'], ['value' => '0.5']);
        Setting::updateOrCreate(['key' => 'calc_images_per_hour', 'brand' => 'rp'], ['value' => '6']);

        $migration->up();

        $this->assertSame('12', Setting::where('key', 'calc_outdoor_images_per_hour')->where('brand', 'rp')->value('value'));
        $this->assertNull(Setting::where('key', 'calc_outdoor_multiplier')->where('brand', 'rp')->value('value'));

        // custom value: preiserhaltend
        Setting::updateOrCreate(['key' => 'calc_outdoor_multiplier', 'brand' => 'rp'], ['value' => '0.25']);

        $migration->up();

        $this->assertSame('24', Setting::where('key', 'calc_outdoor_images_per_hour')->where('brand', 'rp')->value('value'));
    }

    // ------------------------------------------------------------------
    // PUT — Validierungsfehler (422)
    // ------------------------------------------------------------------

    #[DataProvider('invalidCalcPayloadProvider')]
    public function test_update_rejects_invalid_calc_payload(array $payload, string $expectedErrorKey): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload($payload))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$expectedErrorKey]);
    }

    public static function invalidCalcPayloadProvider(): array
    {
        return [
            'calc_base_price negativ' => [['calc_base_price' => '-1'], 'calc_base_price'],
            'calc_hourly_rate negativ' => [['calc_hourly_rate' => '-0.01'], 'calc_hourly_rate'],
            // Dezimal unter dem Mindestwert und knapp darüber: die `integer`-Regel
            // greift unabhängig vom Mindestwert, sonst fielen sie durch.
            'calc_base_price dezimal' => [['calc_base_price' => '60.5'], 'calc_base_price'],
            'calc_hourly_rate dezimal' => [['calc_hourly_rate' => '120.5'], 'calc_hourly_rate'],
            'calc_base_price unter dem Mindestwert' => [['calc_base_price' => '4.99'], 'calc_base_price'],
            'calc_images_per_hour null (min:1)' => [['calc_images_per_hour' => 0], 'calc_images_per_hour'],
            'calc_base_price nicht-numerisch' => [['calc_base_price' => 'abc'], 'calc_base_price'],
            'calc_hourly_rate nicht-numerisch' => [['calc_hourly_rate' => 'free'], 'calc_hourly_rate'],
            'calc_images_per_hour dezimal (integer-rule)' => [['calc_images_per_hour' => '1.5'], 'calc_images_per_hour'],
            'calc_images_per_hour negativ' => [['calc_images_per_hour' => -3], 'calc_images_per_hour'],
            'calc_images_per_hour nicht-numerisch' => [['calc_images_per_hour' => 'many'], 'calc_images_per_hour'],
            'calc_outdoor_images_per_hour null (min:1)' => [['calc_outdoor_images_per_hour' => 0], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour dezimal (integer-rule)' => [['calc_outdoor_images_per_hour' => '4.5'], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour negativ' => [['calc_outdoor_images_per_hour' => -2], 'calc_outdoor_images_per_hour'],
            'calc_outdoor_images_per_hour nicht-numerisch' => [['calc_outdoor_images_per_hour' => 'many'], 'calc_outdoor_images_per_hour'],
        ];
    }

    public function test_update_rejects_when_required_multipliers_missing(): void
    {
        // mult_* sind required — ohne sie 422 (Verhalten eingefroren).
        // `calc_base_price` is a valid cent amount here, so the only reported
        // errors are the three missing multipliers.
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', [
                'calc_base_price' => 5000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'mult_commercial',
                'mult_unlimited',
                'mult_international',
            ])
            // The amount itself was acceptable — only the multipliers are missing.
            ->assertJsonMissingValidationErrors(['calc_base_price']);
    }

    // ------------------------------------------------------------------
    // PUT — Authorisierung (403 / 401)
    // ------------------------------------------------------------------

    public function test_update_rejects_non_admin_user_with_403(): void
    {
        // Plain User ohne Admin-Rolle → ManagementMiddleware 403
        $user = User::factory()->create(); // keine Rollen
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/management/settings/license-terms', $this->validCalcPayload())
            ->assertStatus(403);
    }

    public function test_update_rejects_unauthenticated_request_with_401(): void
    {
        // Kein Token → auth:api Middleware 401
        $this->putJson('/api/management/settings/license-terms', $this->validCalcPayload())
            ->assertStatus(401);
    }
}
