<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report behind the "Database Setup Policy": the values a seed would
 * overwrite in production, named before the seed runs.
 *
 * The seeder is authoritative for the keys it declares and writes them with an
 * `upsert`, so a `db:seed --force` replaces every value an operator set
 * through the UI. The command compares the seeder's **own** values with the
 * stored ones and names every key that would change.
 */
class CheckSeededSettingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_nothing_when_the_stored_values_are_the_seeder_s(): void
    {
        $this->storeSeederValues();

        $this->artisan('ops:check-seeded-settings')
            ->expectsOutputToContain('ein Seed ändert nichts')
            ->assertExitCode(0);
    }

    public function test_it_names_a_value_that_would_be_overwritten(): void
    {
        $this->storeSeederValues();
        Setting::updateOrCreate(
            ['key' => 'bank_iban', 'brand' => 'rp'],
            ['value' => 'ATXX XXXX XXXX XXXX XXXX'],
        );

        $this->artisan('ops:check-seeded-settings')
            ->expectsOutputToContain('bank_iban: der gespeicherte Wert weicht vom Seeder ab.')
            ->assertExitCode(1);
    }

    public function test_it_names_the_placeholder_bank_data_of_an_unseeded_database(): void
    {
        // The values a migration leaves behind (V005): a fresh database carries
        // the placeholder, and a seed replaces it with the real one. The report
        // has to name it, because that difference is the one that matters.
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'ATXX XXXX XXXX XXXX XXXX']);
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Max Mustermann']);

        $this->artisan('ops:check-seeded-settings')
            ->expectsOutputToContain('bank_holder: der gespeicherte Wert weicht vom Seeder ab.')
            ->assertExitCode(1);
    }

    public function test_the_report_reads_the_seeder_s_own_values(): void
    {
        // The command compares against the seeder's constant, not a copy of it:
        // a second list of values could drift and then name the wrong
        // differences.
        $authoritative = DatabaseSeeder::AUTHORITATIVE_SETTINGS;

        $this->assertArrayHasKey('base_price', $authoritative);
        $this->assertArrayHasKey('bank_iban', $authoritative);
        $this->assertArrayHasKey('bank_bic', $authoritative);
        $this->assertArrayHasKey('bank_holder', $authoritative);
        $this->assertSame('8000', $authoritative['base_price']);
        $this->assertSame('DE96100110012179986174', $authoritative['bank_iban']);
        $this->assertCount(28, $authoritative);
    }

    private function storeSeederValues(): void
    {
        foreach (DatabaseSeeder::AUTHORITATIVE_SETTINGS as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key, 'brand' => 'rp'],
                ['value' => $value],
            );
        }
    }
}
