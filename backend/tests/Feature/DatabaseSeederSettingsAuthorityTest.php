<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the `settings` write path of `DatabaseSeeder`.
 *
 * Defect (2026-09-27): the seeder wrote its declared settings with
 * `insertOrIgnore`, which is a no-op for a row that already exists. Several of
 * its keys are created by earlier migrations (V004 inserts `base_price =>
 * '35.00'`, `term_web`, `term_print`, `term_original`; V005 inserts the three
 * bank keys), so seven of the seeder's 28 values were silently discarded and
 * the migration's row survived.
 *
 * The concrete production symptom: `base_price = 35.00` is 35 cents, the
 * calculator settings card hydrates euros with `/100` and submits
 * `Math.round(x * 100)`, so the round trip produced `srp_base_price = 35`,
 * which the `min:500` rule on the licence-terms endpoint rejects with a 422.
 * That broke the two `package-calculator-config.spec.ts` E2E tests on every
 * attempt. The rule is correct; the seeded value was the defect.
 */
class DatabaseSeederSettingsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 28 keys `DatabaseSeeder::seedCatalogForBrand()` declares.
     *
     * Kept as a literal rather than derived from the seeder so the test fails
     * when a value is changed, not when the expectation follows the change.
     */
    private const DECLARED_SETTINGS = [
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
        'calc_base_price' => '50',
        'calc_hourly_rate' => '80',
        'calc_images_per_hour' => '6',
        'calc_outdoor_images_per_hour' => '8',
        'calc_flatrate_multiplier' => '1.2',
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

    /**
     * The seven keys a migration already created, so `insertOrIgnore` skipped
     * them and the migration's value survived the seed.
     */
    private const MIGRATION_OWNED_KEYS = [
        'base_price',
        'term_web',
        'term_print',
        'term_original',
        'bank_holder',
        'bank_iban',
        'bank_bic',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Config::set('admin.email', 'seed-admin@example.test');
        Config::set('admin.password', 'seed-password-for-tests');
    }

    /**
     * The regression test for the defect itself: seven values the seeder
     * declares but `insertOrIgnore` never wrote.
     *
     * Asserted as one map so a failure prints every affected key at once
     * instead of stopping at the first one.
     */
    public function test_seed_writes_the_seven_keys_that_migrations_had_already_created(): void
    {
        $this->seed(DatabaseSeeder::class);

        $stored = [];
        foreach (array_keys(self::DECLARED_SETTINGS) as $key) {
            $stored[$key] = Setting::query()
                ->where('key', $key)
                ->where('brand', Brand::B2B->value)
                ->value('value');
        }

        $expected = array_intersect_key(self::DECLARED_SETTINGS, array_flip(self::MIGRATION_OWNED_KEYS));
        $actual = array_intersect_key($stored, $expected);

        $this->assertSame($expected, $actual);
    }

    /**
     * The failing CI save, reproduced as a backend test.
     *
     * Mirrors what `CalculatorSettingsCard` does on a real save: hydrate the
     * form from the public licence-terms response (`/100` for the cent-valued
     * `srp_*` fields), then submit (`Math.round(x * 100)`) together with the
     * `calc_*` fields and the required `mult_*` multipliers.
     *
     * With the defect present the seeded `base_price` is `35.00`, the card
     * submits `srp_base_price = 35`, and the `min:500` rule answers 422 —
     * the exact failure the two E2E tests hit.
     */
    public function test_seeded_base_price_survives_the_calculator_card_round_trip(): void
    {
        $this->seed(DatabaseSeeder::class);

        $terms = $this->getJson('/api/settings/license-terms');
        $terms->assertOk();

        $payload = $this->calculatorCardPayload($terms->json());

        $this->withHeaders(['Authorization' => 'Bearer '.$this->seededAdminToken()])
            ->putJson('/api/management/settings/license-terms', $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        // The card's round trip must be a no-op on the value the seeder
        // declared: read `8000`, render 80 €, submit 8000 cents.
        $this->assertSame(8000, $payload['srp_base_price']);
        $this->assertSame(
            '8000',
            Setting::query()->where('key', 'base_price')->where('brand', Brand::B2B->value)->value('value')
        );
    }

    /**
     * Guards the whole declared settings surface, not just the seven keys the
     * defect was observed on: every value the seeder declares has to be the
     * value the seeder stores.
     */
    public function test_seed_writes_every_declared_settings_key(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(self::DECLARED_SETTINGS, $this->storedDeclaredSettings());
    }

    /**
     * Non-regression guard for the write path: `upsert` replaced
     * `insertOrIgnore`, which was idempotent. Seeding twice must still not
     * error, must not change a value, and must not duplicate a row.
     */
    public function test_seeding_twice_leaves_the_same_single_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $afterFirstSeed = $this->storedDeclaredSettings();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($afterFirstSeed, $this->storedDeclaredSettings());

        foreach (array_keys(self::DECLARED_SETTINGS) as $key) {
            $this->assertSame(
                1,
                DB::table('settings')->where('key', $key)->where('brand', Brand::B2B->value)->count(),
                "Expected exactly one settings row for [{$key}] after seeding twice."
            );
        }
    }

    /**
     * JWT of the admin the seeder itself created, i.e. the account the E2E
     * suite logs in with — not a separately constructed fixture user.
     */
    private function seededAdminToken(): string
    {
        $admin = User::query()->where('email', config('admin.email'))->firstOrFail();

        return auth('api')->login($admin);
    }

    /**
     * The declared settings as they are stored for the active brand.
     *
     * @return array<string, string>
     */
    private function storedDeclaredSettings(): array
    {
        $stored = [];

        foreach (array_keys(self::DECLARED_SETTINGS) as $key) {
            $stored[$key] = (string) Setting::query()
                ->where('key', $key)
                ->where('brand', Brand::B2B->value)
                ->value('value');
        }

        return $stored;
    }

    /**
     * The request body `CalculatorSettingsCard` produces from a licence-terms
     * response (`mapApiToForm` then `mapFormToApi`, plus the `mult_*`
     * fallbacks from `onSubmit`).
     *
     * The cent-valued `srp_*` fields are divided by 100 when the form is
     * hydrated and multiplied back by 100 with `Math.round` on submit, so the
     * round trip is exercised exactly as the browser performs it.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, float|int>
     */
    private function calculatorCardPayload(array $terms): array
    {
        $flatrateSurcharge = round(((float) $terms['calc_flatrate_multiplier'] - 1) * 100);

        return [
            'calc_base_price' => (float) $terms['calc_base_price'],
            'calc_hourly_rate' => (float) $terms['calc_hourly_rate'],
            'calc_images_per_hour' => (int) $terms['calc_images_per_hour'],
            'calc_outdoor_images_per_hour' => (int) $terms['calc_outdoor_images_per_hour'],
            'calc_flatrate_multiplier' => 1 + ($flatrateSurcharge / 100),
            'srp_base_price' => $this->eurosToCents($terms['srp_base_price']),
            'srp_setup_fee' => $this->eurosToCents($terms['srp_setup_fee']),
            'srp_privacy_fee' => $this->eurosToCents($terms['srp_privacy_fee']),
            'srp_extra_image_fee' => $this->eurosToCents($terms['srp_extra_image_fee']),
            'mult_commercial' => $terms['mult_commercial'] ?: '2.0',
            'mult_unlimited' => $terms['mult_unlimited'] ?: '1.5',
            'mult_international' => $terms['mult_international'] ?: '1.5',
        ];
    }

    /**
     * `mapApiToForm`'s `/100` followed by `mapFormToApi`'s
     * `Math.round(x * 100)`, in that order — the order the card applies them.
     */
    private function eurosToCents(mixed $storedCents): int
    {
        return (int) round(((float) $storedCents / 100) * 100);
    }
}
