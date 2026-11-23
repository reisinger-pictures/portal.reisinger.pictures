<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurEntry;
use App\Support\BrandRegistry;

class EurYearsApiTest extends EurBookkeepingTestCase
{
    public function test_years_require_authentication(): void
    {
        $this->getJson('/api/management/eur/years')->assertUnauthorized();
    }

    public function test_years_exposes_default_supported_and_both_warning_examples(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/years');

        $response->assertOk();
        $response->assertJsonPath('default_year', 2026);
        $response->assertJsonPath('supported', [2024, 2025, 2026]);
        $response->assertJsonCount(2, 'warnings');
        $response->assertJsonPath('warnings.0.year', 2023);
        $response->assertJsonPath('warnings.0.code', 'EUE-003');
        $response->assertJsonPath('warnings.0.supported', false);
        $response->assertJsonPath('warnings.0.warning', '⚠ Die Tabelle nicht offiziell mit 2023 kompatibel. Kontaktieren Sie uns für den Zugang zu einer älteren Version (Kauf der Lifetime-Lizenz erforderlich).');
        $response->assertJsonPath('warnings.1.year', 2027);
        $response->assertJsonPath('warnings.1.code', 'EUE-002');
        $response->assertJsonPath('warnings.1.supported', false);
        $response->assertJsonPath('warnings.1.warning', '⚠ Die Tabelle nicht offiziell mit 2027 kompatibel. Bitte auf die neueste Version der Tabelle umsteigen.');
    }

    public function test_active_years_lists_only_years_with_bookings_in_the_current_brand(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        foreach ([[2024, 3], [2026, 5], [2026, 11]] as [$year, $month]) {
            EurEntry::create([
                'brand' => $brand, 'year' => $year, 'month' => $month, 'day' => 5,
                'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
                'amount_cents' => 10000, 'business_amount_cents' => 10000,
            ]);
        }

        // Buchung eines fremden Brands darf die aktiven Jahre nicht beeinflussen.
        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2025, 'month' => 1, 'day' => 5,
            'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/years');

        $response->assertOk();
        $response->assertJsonPath('active_years', [2024, 2026]);
    }

    public function test_supported_years_are_accepted_and_2027_is_rejected(): void
    {
        $token = $this->tokenFor();

        foreach ([2024, 2025, 2026] as $year) {
            $this->withHeaders($this->bearer($token))
                ->getJson("/api/management/eur/month?year={$year}&month=5")
                ->assertOk();
        }

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/month?year=2027&month=5');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('year');
    }
}
