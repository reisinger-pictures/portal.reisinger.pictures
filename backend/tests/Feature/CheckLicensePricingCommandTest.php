<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\LicenseTermRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The counter-check before a deploy that touches `base_price`: the check is a
 * command, and the command must agree with the write path.
 *
 * The command resolves the effective values the way the buy flow resolves them
 * and compares them against `LicenseTermRules::RULES` — the same table the
 * settings controller derives its request contract from. The tests below pin
 * both the verdicts and that coupling, so a rule that drifts in one place
 * cannot leave the other reporting a different answer.
 */
class CheckLicensePricingCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The values the seeder writes for the B2B brand
     * (`DatabaseSeeder`), in cents.
     *
     * @var array<string, string>
     */
    private const SEEDED_PRICES = [
        'base_price' => '8000',
        'calc_base_price' => '5000',
        'calc_hourly_rate' => '8000',
        'price_web' => '7500',
        'price_print' => '14500',
        'price_original' => '45000',
        'setup_fee' => '5000',
        'privacy_fee' => '20000',
        'extra_image_fee' => '1500',
    ];

    public function test_it_passes_for_the_seeded_prices(): void
    {
        $this->seedPrices();

        $this->artisan('ops:check-license-pricing')
            ->assertExitCode(0);
    }

    public function test_it_reports_the_effective_amounts_in_cents_and_euros(): void
    {
        $this->seedPrices();

        // One expectation per run: the console mock hands a write to the first
        // matching expectation, so two substrings of the same table row would
        // leave the second one unfulfilled.
        $this->artisan('ops:check-license-pricing')
            ->expectsOutputToContain('base_price')
            ->assertExitCode(0);

        $this->artisan('ops:check-license-pricing')
            ->expectsOutputToContain('80.00')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_a_price_is_below_the_minimum(): void
    {
        $this->seedPrices(['base_price' => '35']);

        $this->artisan('ops:check-license-pricing')
            ->expectsOutputToContain('base_price: below the minimum')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_price_is_stored_in_euros(): void
    {
        // The defect a fresh database once carried: '35.00' is 35 euros, but
        // the table stores cents, so the amount reads as 35 cents.
        $this->seedPrices(['base_price' => '35.00']);

        $this->artisan('ops:check-license-pricing')
            ->expectsOutputToContain('base_price: not an integer amount of cents')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_price_is_missing(): void
    {
        $this->seedPrices(['base_price' => null]);

        $this->artisan('ops:check-license-pricing')
            ->expectsOutputToContain('base_price: no value')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_an_expected_value_differs(): void
    {
        $this->seedPrices();

        $this->artisan('ops:check-license-pricing', ['--expect' => 'base_price=7500'])
            ->expectsOutputToContain('base_price: 8000 cents, expected 7500 cents')
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_expectation_for_an_unknown_key(): void
    {
        $this->seedPrices();

        $this->artisan('ops:check-license-pricing', ['--expect' => 'base_prices=8000'])
            ->expectsOutputToContain('is not a licence money key')
            ->assertExitCode(1);
    }

    public function test_the_check_and_the_write_path_agree_on_the_minimum(): void
    {
        // The command reads the minimum from `LicenseTermRules::RULES`, the
        // table the settings controller derives its request contract from. The
        // same rule must reject the write and the check.
        $rule = LicenseTermRules::RULES['base_price'];
        $minimum = LicenseTermRules::minimum('base_price');

        $this->assertSame(500, $minimum);
        $this->assertTrue(Validator::make(['base_price' => $minimum - 1], ['base_price' => $rule])->fails());
        $this->assertTrue(Validator::make(['base_price' => $minimum], ['base_price' => $rule])->passes());
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function seedPrices(array $overrides = []): void
    {
        foreach (self::SEEDED_PRICES as $key => $value) {
            if (array_key_exists($key, $overrides)) {
                $value = $overrides[$key];
            }

            if ($value === null) {
                Setting::query()->where('key', $key)->where('brand', 'rp')->delete();

                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key, 'brand' => 'rp'],
                ['value' => $value],
            );
        }
    }
}
