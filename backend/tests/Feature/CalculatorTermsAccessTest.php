<?php

namespace Tests\Feature;

use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Setting;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Access contract of the public calculator input endpoint (owner decision
 * D-62):
 *
 * `GET /api/settings/calculator-terms` is public by design and carries exactly
 * the nine parameters the package calculator derives its prices from. Those
 * derived prices are already published on the website, so the inputs are not a
 * secret. The complete licence terms and the `price_*`/`mult_*`/`base_price`
 * internals stay behind the super-admin gate of `/settings/license-terms`
 * (D-27) — this endpoint must never widen that gate.
 */
class CalculatorTermsAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact wire contract, in the order the controller projects it.
     */
    private const CALCULATOR_KEYS = [
        'calc_base_price',
        'calc_hourly_rate',
        'calc_images_per_hour',
        'calc_outdoor_images_per_hour',
        'calc_flatrate_multiplier',
        'srp_base_price',
        'srp_setup_fee',
        'srp_privacy_fee',
        'srp_extra_image_fee',
    ];

    private function setBrand(string $id): void
    {
        BrandRegistry::set(new BrandConfig(
            id: $id,
            name: $id,
            theme: 'rp',
            portalName: $id,
            impressumUrl: null,
            logoPath: null,
        ));
    }

    public function test_calculator_terms_is_anonymous_200_with_exactly_the_nine_keys(): void
    {
        $response = $this->getJson('/api/settings/calculator-terms')->assertOk();

        // "Exactly the keys": no licence text, no pricing strategy, no volume
        // preset and no internal price parameter beyond the nine inputs.
        $this->assertSame(self::CALCULATOR_KEYS, array_keys($response->json()));

        // Symfony normalises the directive order; the semantics are
        // `Cache-Control: public, max-age=60`.
        $response->assertHeader('Cache-Control', 'max-age=60, public');
    }

    public function test_internal_price_parameters_and_licence_texts_stay_off_the_payload(): void
    {
        // Written so the assertion cannot pass merely because no row exists.
        Setting::updateOrCreate(['key' => 'base_price', 'brand' => 'rp'], ['value' => '8000']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '7500']);
        Setting::updateOrCreate(['key' => 'term_editorial', 'brand' => 'rp'], ['value' => 'Redaktionelle Nutzung']);

        $response = $this->getJson('/api/settings/calculator-terms')->assertOk();

        // Everything the full license-terms endpoint serves that is not one of
        // the nine calculator inputs. Reusing the other test's constant means a
        // newly added internal key there is checked here as well.
        $stillInternal = array_values(array_diff(
            LicenseTermsAccessTest::INTERNAL_PRICE_KEYS,
            self::CALCULATOR_KEYS,
        ));

        foreach ($stillInternal as $key) {
            $response->assertJsonMissingPath($key);
        }

        foreach (['pricing_strategy', 'volume_pricing', 'commercial', '1_year', 'unlimited', 'territory_national'] as $key) {
            $response->assertJsonMissingPath($key);
        }
    }

    public function test_money_is_projected_as_integer_cents_and_counts_keep_text_typing(): void
    {
        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '5000']);
        Setting::updateOrCreate(['key' => 'calc_hourly_rate', 'brand' => 'rp'], ['value' => '10000']);
        Setting::updateOrCreate(['key' => 'calc_images_per_hour', 'brand' => 'rp'], ['value' => '6']);
        Setting::updateOrCreate(['key' => 'calc_outdoor_images_per_hour', 'brand' => 'rp'], ['value' => '8']);
        Setting::updateOrCreate(['key' => 'calc_flatrate_multiplier', 'brand' => 'rp'], ['value' => '1.25']);
        // The four money keys keep their unprefixed settings names; the wire
        // names are the legacy `srp_*` spellings.
        Setting::updateOrCreate(['key' => 'base_price', 'brand' => 'rp'], ['value' => '14900']);
        Setting::updateOrCreate(['key' => 'setup_fee', 'brand' => 'rp'], ['value' => '5000']);
        Setting::updateOrCreate(['key' => 'privacy_fee', 'brand' => 'rp'], ['value' => '25000']);
        Setting::updateOrCreate(['key' => 'extra_image_fee', 'brand' => 'rp'], ['value' => '1500']);

        $response = $this->getJson('/api/settings/calculator-terms')->assertOk();
        $payload = $response->json();

        // Money: JSON integer cents. `assertSame` separates 5000 from '5000';
        // `assertIsInt`/`assertIsString` pin the JSON type, not just the value.
        foreach (['calc_base_price' => 5000, 'calc_hourly_rate' => 10000] as $key => $cents) {
            $this->assertIsInt($payload[$key], "$key must be an integer amount of cents.");
            $this->assertSame($cents, $payload[$key]);
        }
        foreach ([
            'srp_base_price' => 14900,
            'srp_setup_fee' => 5000,
            'srp_privacy_fee' => 25000,
            'srp_extra_image_fee' => 1500,
        ] as $key => $cents) {
            $this->assertIsInt($payload[$key], "$key must be an integer amount of cents.");
            $this->assertSame($cents, $payload[$key]);
        }

        // Counts and the dimensionless factor keep the stored text typing.
        foreach ([
            'calc_images_per_hour' => '6',
            'calc_outdoor_images_per_hour' => '8',
            'calc_flatrate_multiplier' => '1.25',
        ] as $key => $value) {
            $this->assertIsString($payload[$key], "$key must keep its text typing.");
            $this->assertSame($value, $payload[$key]);
        }
    }

    public function test_brand_scoped_row_is_read_and_a_foreign_brand_row_is_ignored(): void
    {
        // The middleware would derive the brand from the test host (always rp);
        // set it explicitly, as BrandScopingLicenseTermsTest does.
        $this->withoutMiddleware(BrandContextMiddleware::class);
        $this->setBrand('rp');

        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'rp'], ['value' => '5000']);
        Setting::updateOrCreate(['key' => 'calc_base_price', 'brand' => 'xx'], ['value' => '9999']);

        $this->getJson('/api/settings/calculator-terms')
            ->assertOk()
            ->assertJsonPath('calc_base_price', 5000);
    }

    public function test_endpoint_stays_available_without_any_setting_row_and_returns_null(): void
    {
        // No seeder runs here: an absent row must stay `null`, never become a
        // price of `0` (a free quote is a different statement from an absent
        // one) and must not turn the endpoint into an error.
        Setting::query()->delete();

        $response = $this->getJson('/api/settings/calculator-terms')->assertOk();

        $this->assertSame(self::CALCULATOR_KEYS, array_keys($response->json()));
        foreach (self::CALCULATOR_KEYS as $key) {
            $response->assertJsonPath($key, null);
        }
    }

    public function test_route_is_public_and_throttled_under_its_name(): void
    {
        $route = Route::getRoutes()->getByName('api.settings.calculator-terms');
        $this->assertNotNull($route, 'Route api.settings.calculator-terms ist nicht registriert.');

        $middleware = $route->gatherMiddleware();
        $this->assertContains('throttle:api', $middleware);
        // No auth guard, in particular no super_admin gate: the endpoint is the
        // build-time input for the static website (D-62).
        $this->assertNotContains('auth:api', $middleware);
        $this->assertNotContains('super_admin', $middleware);
    }
}
