<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

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
        $response->assertJsonPath('warnings.1.year', 2027);
        $response->assertJsonPath('warnings.1.code', 'EUE-002');
        $response->assertJsonPath('warnings.1.supported', false);
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
