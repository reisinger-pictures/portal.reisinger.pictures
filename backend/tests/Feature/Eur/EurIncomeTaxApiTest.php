<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

/**
 * EAR · POST /est/calc — Kennzahlen, Steuerbilanz und Per-KZ-Arrays.
 */
class EurIncomeTaxApiTest extends EurBookkeepingTestCase
{
    public function test_calc_requires_authentication(): void
    {
        $this->postJson('/api/management/eur/est/calc', ['profit_cents' => 100000])->assertUnauthorized();
    }

    public function test_calc_returns_additive_tax_fields(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
            'year' => 2026,
            'profit_cents' => 200000,
            'mode' => 'total',
            'employment_income_cents' => 30000,
            'wage_tax_cents' => 4000,
            'prepayment_cents' => 5000,
            'rental_forecast_cents' => 7000,
            'arbeitsplatzpauschale_cents' => 20000,
            'ergonomisches_mobiliar_cents' => 10000,
            'entries' => [
                ['direction' => 'income', 'code' => '9040', 'amount_cents' => 200000],
                ['direction' => 'expense', 'code' => '9130', 'amount_cents' => 20000],
                ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
            ],
        ]);

        $response->assertOk();

        foreach (['year', 'mode', 'profit_cents', 'basic_allowance_cents', 'taxable_cents',
            'income_tax_cents', 'tax_free_threshold_cents', 'stages', 'e1a', 'e1b', 'deductions_cents'] as $key) {
            $this->assertArrayHasKey($key, $response->json());
        }

        $response->assertJsonPath('kz.245', 30000)
            ->assertJsonPath('kz.260', 4000)
            ->assertJsonPath('kz.U13', 7000)
            ->assertJsonPath('kz.U26', 5000)
            ->assertJsonPath('kz.E40', 20000)
            ->assertJsonPath('kz.E39', 10000)
            ->assertJsonPath('steuerbilanz.betriebliche_einnahmen_cents', 200000)
            ->assertJsonPath('steuerbilanz.betriebliche_ausgaben_cents', 20000)
            ->assertJsonPath('steuerbilanz.afa_cents', 20000)
            ->assertJsonPath('steuerbilanz.pauschalen.e40_code', '9215')
            ->assertJsonPath('e1a_e1b.E1A.9040', 200000)
            ->assertJsonPath('e1a_e1b.E1B.9460', 50000);
    }

    public function test_calc_uses_the_tariff_of_the_selected_year(): void
    {
        // EUE-089 … EUE-093: 2024/2025 dürfen nicht mit 2026-Grenzen rechnen.
        $token = $this->tokenFor();

        $thresholds = [
            2024 => 1281600,
            2025 => 1330800,
            2026 => 1353900,
        ];

        foreach ($thresholds as $year => $expected) {
            $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
                'year' => $year,
                'profit_cents' => 200000,
            ]);

            $response->assertOk()
                ->assertJsonPath('year', $year)
                ->assertJsonPath('tax_free_threshold_cents', $expected);
        }
    }

    public function test_mode_is_reflected_in_all_new_fields(): void
    {
        $token = $this->tokenFor();
        $payload = [
            'profit_cents' => 200000,
            'rental_forecast_cents' => 7000,
            'arbeitsplatzpauschale_cents' => 20000,
            'entries' => [
                ['direction' => 'income', 'code' => '9040', 'amount_cents' => 200000],
                ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
            ],
        ];

        $rental = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/est/calc', $payload + ['mode' => 'rental']);
        $rental->assertOk()
            ->assertJsonPath('mode', 'rental')
            ->assertJsonPath('kz.E40', 0)
            ->assertJsonPath('steuerbilanz.betriebliche_einnahmen_cents', 0);

        $business = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/est/calc', $payload + ['mode' => 'business']);
        $business->assertOk()
            ->assertJsonPath('mode', 'business')
            ->assertJsonPath('kz.U13', 0)
            ->assertJsonPath('steuerbilanz.betriebliche_einnahmen_cents', 200000);
    }

    public function test_invalid_mode_and_negative_money_are_rejected(): void
    {
        $token = $this->withHeaders($this->bearer($this->tokenFor()));

        $token->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 100,
            'mode' => 'unknown',
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $token->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 100,
            'prepayment_cents' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('prepayment_cents');
    }
}
