<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaEntry;
use App\Support\BrandRegistry;
use Illuminate\Support\Carbon;

class EaYearsApiTest extends EaBookkeepingTestCase
{
    public function test_years_require_authentication(): void
    {
        $this->getJson('/api/management/ea/years')->assertUnauthorized();
    }

    public function test_years_exposes_pinned_and_bookable_years_without_a_warning_list(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/years');

        $response->assertOk();
        $response->assertJsonPath('pinned_years', [2024, 2025, 2026]);
        $response->assertJsonPath('bookable_years', [2026]);
        $response->assertJsonPath('last_pinned_year', 2026);
        $response->assertJsonPath('default_year', 2026);
        $response->assertJsonMissingPath('warnings');
        $response->assertJsonMissingPath('supported');
    }

    public function test_default_year_is_the_current_bookable_year_and_falls_back_below_2026(): void
    {
        // Der Token wird nach dem Zeitreisen ausgestellt, damit er nicht
        // durch die fixierte Uhrzeit abläuft.
        $this->travelTo(Carbon::parse('2028-03-01'));
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/years')
            ->assertOk()
            ->assertJsonPath('default_year', 2028);

        // Unterhalb des ersten Buchungsjahres gilt das letzte Regeljahr.
        $this->travelTo(Carbon::parse('2025-03-01'));
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/years')
            ->assertOk()
            ->assertJsonPath('default_year', 2026);
    }

    public function test_active_years_lists_only_years_with_bookings_in_the_current_brand(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        foreach ([[2024, 3], [2026, 5], [2026, 11]] as [$year, $month]) {
            EaEntry::create([
                'brand' => $brand, 'year' => $year, 'month' => $month, 'day' => 5,
                'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
                'amount_cents' => 10000, 'business_amount_cents' => 10000,
            ]);
        }

        // Buchung eines fremden Brands darf die aktiven Jahre nicht beeinflussen.
        EaEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2025, 'month' => 1, 'day' => 5,
            'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/years');

        $response->assertOk();
        $response->assertJsonPath('active_years', [2024, 2026]);
    }

    public function test_month_accepts_bookable_years_and_rejects_rule_only_years(): void
    {
        $token = $this->tokenFor();

        foreach ([2026, 2027] as $year) {
            $this->withHeaders($this->bearer($token))
                ->getJson("/api/management/ea/month?year={$year}&month=5")
                ->assertOk();
        }

        foreach ([2023, 2024, 2025] as $year) {
            $response = $this->withHeaders($this->bearer($token))
                ->getJson("/api/management/ea/month?year={$year}&month=5");

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors('year');
        }
    }
}
