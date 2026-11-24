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

    public function test_calc_exposes_the_allocation_and_neutral_monthly_results(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 200000,
        ]);

        $response->assertOk()
            ->assertJsonPath('est_allocation.tax_cents', 0) // 170 000 ct liegen unter der ersten Tarifgrenze.
            ->assertJsonPath('monthlyResults.annual', null);

        $this->assertCount(12, $response->json('monthlyResults.months'));
        $this->assertNull($response->json('monthlyResults.months.0.est_sv_cents'));
        $this->assertNull($response->json('monthlyResults.months.11.net_per_hour_cents'));
    }

    public function test_calc_monthly_results_follow_the_pinned_est_allocation(): void
    {
        $token = $this->tokenFor();
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[] = ['month' => $month, 'gross_profit_cents' => 100000, 'hours_minutes' => 600];
        }

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
            'year' => 2026,
            'profit_cents' => 900000,
            'mode' => 'total',
            'employment_income_cents' => 2000000,
            'prepayment_cents' => 100000,
            'wage_tax_cents' => 50000,
            'entries' => [
                ['direction' => 'income', 'code' => '9040', 'amount_cents' => 900000],
                ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 140000],
            ],
            'months' => $months,
        ]);

        $response->assertOk()
            ->assertJsonPath('kz.U12', 140000)
            ->assertJsonPath('est_allocation.total_income_cents', 2905000)
            ->assertJsonPath('est_allocation.tax_cents', 380800)
            ->assertJsonPath('est_allocation.employment_tax_cents', 129220)
            ->assertJsonPath('est_allocation.rental_tax_cents', 28000)
            ->assertJsonPath('est_allocation.business_tax_cents', 223580)
            ->assertJsonPath('est_allocation.due_cents', 230800)
            ->assertJsonPath('monthlyResults.year', 2026)
            ->assertJsonPath('monthlyResults.annual.minimum_sv_cents', 16080)
            ->assertJsonPath('monthlyResults.annual.months_with_data', 12);

        $payload = $response->json();
        $monthly = $payload['monthlyResults'];
        $sum = 0;

        foreach ($monthly['months'] as $row) {
            $this->assertSame(100000, $row['gross_profit_cents']);
            $this->assertSame($row['gross_profit_cents'] - $row['est_sv_cents'], $row['net_profit_cents']);
            $this->assertSame((int) round($row['net_profit_cents'] * 60 / 600), $row['net_per_hour_cents']);
            $this->assertSame($monthly['annual']['allocation_basis_cents'], $row['basis_cents']);
            $sum += $row['est_sv_cents'];
        }

        // Die zwölf Monatsanteile ergeben genau die Jahresbelastung (U24+U25+SV).
        $this->assertSame($monthly['annual']['burden_cents'], $sum);
        $this->assertSame($monthly['annual']['allocated_cents'], $sum);
    }

    public function test_calc_monthly_results_are_null_for_months_without_data_and_zero_hours(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 200000,
            'months' => [
                ['month' => 3, 'gross_profit_cents' => 50000, 'hours_minutes' => 0],
            ],
        ]);

        $response->assertOk();

        $months = $response->json('monthlyResults.months');

        $this->assertCount(12, $months);
        $this->assertSame(3, $months[2]['month']);
        $this->assertSame(50000, $months[2]['gross_profit_cents']);
        $this->assertNull($months[2]['net_per_hour_cents']);
        $this->assertNotNull($months[2]['est_sv_cents']);
        $this->assertNull($months[0]['gross_profit_cents']);
        $this->assertNull($months[0]['est_sv_cents']);
        $this->assertNull($months[0]['net_profit_cents']);
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

    public function test_calc_accepts_the_shipped_frontend_payload(): void
    {
        $months = [];

        for ($month = 1; $month <= 6; $month++) {
            $months[] = ['month' => $month, 'gross_profit_cents' => 100000, 'hours_minutes' => 600];
        }

        $response = $this->withHeaders($this->bearer($this->tokenFor()))->postJson('/api/management/eur/est/calc', [
            'year' => 2026,
            'profit_cents' => 200000,
            'mode' => 'other_profits',
            'employment_income_cents' => 2000000,
            'wage_tax_cents' => 50000,
            'prepayment_cents' => 100000,
            'arbeitsplatzpauschale_cents' => 120000,
            'ergonomisches_mobiliar_cents' => 30000,
            'prognose' => true,
            'business_forecast_cents' => 25000,
            'entries' => [
                ['direction' => 'income', 'code' => '9040', 'amount_cents' => 200000],
                ['direction' => 'expense', 'code' => '9130', 'amount_cents' => 20000],
                ['direction' => 'income', 'code' => 'E1B 9460', 'amount_cents' => 50000],
            ],
            'months' => $months,
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'other_profits')
            ->assertJsonPath('steuerbilanz.pauschalen.e40_code', '9217')
            ->assertJsonPath('steuerbilanz.pauschalen.e39_code', '9216')
            ->assertJsonPath('steuerbilanz.pauschalen.e39_warning', 'Wert löschen (wegen 9217 große Pauschale) →')
            ->assertJsonPath('est_allocation.prepayment_cents', 100000)
            ->assertJsonPath('est_allocation.wage_tax_cents', 50000)
            ->assertJsonPath('kz.U26', 100000)
            ->assertJsonPath('kz.260', 50000)
            ->assertJsonPath('kz.U8', 25000) // expliziter Wert hat Vorrang
            ->assertJsonPath('kz.U13', 50000) // 50000 / 6 * 12 − 50000
            ->assertJsonPath('e1a_e1b.E1A.9040', 200000)
            ->assertJsonPath('e1a_e1b.E1B.9460', 50000);
    }

    public function test_calc_accepts_all_six_modes_and_hides_u24_for_gross_wage(): void
    {
        $token = $this->withHeaders($this->bearer($this->tokenFor()));

        foreach (['total', 'business', 'rental', 'forecast', 'gross_wage', 'other_profits'] as $mode) {
            $response = $token->postJson('/api/management/eur/est/calc', [
                'profit_cents' => 200000,
                'mode' => $mode,
            ]);
            $response->assertOk()->assertJsonPath('mode', $mode);

            if ($mode === 'gross_wage') {
                $response->assertJsonPath('est_allocation.business_tax_cents', null);
            }
        }
    }

    public function test_calc_reads_booked_sv_from_code_and_e1a_code(): void
    {
        $token = $this->withHeaders($this->bearer($this->tokenFor()));
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[] = ['month' => $month, 'gross_profit_cents' => 100000, 'hours_minutes' => 600];
        }

        $payload = static fn (string $key): array => [
            'year' => 2026,
            'profit_cents' => 2000000,
            'mode' => 'total',
            'months' => $months,
            'entries' => [
                ['direction' => 'income', 'code' => '9040', 'amount_cents' => 2000000],
                ['direction' => 'expense', $key => '9225', 'amount_cents' => 100000],
            ],
        ];

        $fromCode = $token->postJson('/api/management/eur/est/calc', $payload('code'));
        $fromE1aCode = $token->postJson('/api/management/eur/est/calc', $payload('e1a_code'));

        $fromCode->assertOk();
        $fromE1aCode->assertOk();
        $this->assertSame(
            $fromCode->json('monthlyResults.annual.sv_cents'),
            $fromE1aCode->json('monthlyResults.annual.sv_cents'),
        );
        $this->assertGreaterThan(0, $fromE1aCode->json('monthlyResults.annual.sv_cents'));
    }

    public function test_invalid_prognose_and_negative_forecast_are_rejected(): void
    {
        $token = $this->withHeaders($this->bearer($this->tokenFor()));

        $token->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 100,
            'prognose' => 'vielleicht',
        ])->assertUnprocessable()->assertJsonValidationErrors('prognose');

        $token->postJson('/api/management/eur/est/calc', [
            'profit_cents' => 100,
            'business_forecast_cents' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('business_forecast_cents');
    }
}
