<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurCustomer;
use App\Models\EurEntry;
use App\Models\EurProject;
use App\Support\BrandRegistry;

class EurAnalyticsApiTest extends EurBookkeepingTestCase
{
    public function test_analytics_require_authentication(): void
    {
        $this->getJson('/api/management/eur/analytics')->assertUnauthorized();
    }

    public function test_analytics_defaults_to_2026(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/analytics')
            ->assertOk()
            ->assertJsonPath('year', 2026);
    }

    public function test_analytics_sums_are_integer_cents(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        $customer = EurCustomer::create(['brand' => $brand, 'name' => 'Kunde A']);
        $project = EurProject::create([
            'brand' => $brand, 'eur_customer_id' => $customer->id, 'name' => 'Projekt X',
        ]);

        foreach ([
            ['income', 100000, 100000],
            ['expense', 40000, 25000],
        ] as [$direction, $amount, $business]) {
            EurEntry::create([
                'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
                'direction' => $direction, 'kind_type' => 'Leistung',
                'eur_customer_id' => $customer->id, 'eur_project_id' => $project->id,
                'business_share' => 100, 'amount_cents' => $amount,
                'business_amount_cents' => $business,
            ]);
        }

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/analytics?year=2026');

        $response->assertOk();
        $response->assertJsonPath('by_customer.0.name', 'Kunde A');
        $response->assertJsonPath('by_customer.0.revenue_cents', 100000);
        $response->assertJsonPath('by_customer.0.cost_cents', 25000);
        $response->assertJsonPath('by_customer.0.margin_cents', 75000);
        $response->assertJsonPath('by_project.0.name', 'Projekt X');
        $response->assertJsonPath('by_project.0.margin_cents', 75000);

        $month = collect($response->json('by_month'))->firstWhere('month', 5);
        $this->assertSame(100000, $month['income_cents']);
        $this->assertSame(25000, $month['expense_cents']);
        $this->assertSame(75000, $month['profit_cents']);
    }

    public function test_income_type_matrix_reconciles_with_booked_income(): void
    {
        // EUE-119: Einnahmen-Art × Monat mit Summen und ✓✓-Abgleich.
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        foreach ([['Honorar', 5, 100000], ['Honorar', 6, 50000], ['Miete', 5, 70000]] as [$kind, $month, $amount]) {
            EurEntry::create([
                'brand' => $brand, 'year' => 2026, 'month' => $month, 'day' => 5,
                'direction' => 'income', 'kind_type' => $kind, 'e1a_code' => '9040',
                'business_share' => 100, 'amount_cents' => $amount,
                'business_amount_cents' => $amount,
            ]);
        }

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/analytics/by-income-type?year=2026');

        $response->assertOk()
            ->assertJsonPath('total_cents', 220000)
            ->assertJsonPath('booked_cents', 220000)
            ->assertJsonPath('delta_cents', 0)
            ->assertJsonPath('match', true)
            ->assertJsonPath('types.0.type', 'Honorar')
            ->assertJsonPath('types.0.total_cents', 150000)
            ->assertJsonPath('month_totals.5', 170000);
    }

    public function test_expense_type_matrix_groups_a_av_web_and_fr(): void
    {
        // EUE-120: Ausgaben-Art × Monat mit A/AV/WEB/FR-Herkunft.
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        foreach ([
            ['Ware', '9100', 30000], ['Ware', '9130', 10000],
            ['Fahrt', '9160', 5000], ['Büro', '9230', 2000],
        ] as [$kind, $code, $amount]) {
            EurEntry::create([
                'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
                'direction' => 'expense', 'kind_type' => $kind, 'e1a_code' => $code,
                'business_share' => 100, 'amount_cents' => $amount,
                'business_amount_cents' => $amount,
            ]);
        }

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/analytics/by-expense-type?year=2026');

        $response->assertOk()
            ->assertJsonPath('total_cents', 47000)
            ->assertJsonPath('match', true);

        $ware = collect($response->json('types'))->firstWhere('type', 'Ware');
        $this->assertSame(['A' => 0, 'AV' => 10000, 'WEB' => 30000, 'FR' => 0], $ware['sources']);
        $fahrt = collect($response->json('types'))->firstWhere('type', 'Fahrt');
        $this->assertSame(5000, $fahrt['sources']['FR']);
    }

    public function test_payment_reconciliation_opens_tracks_and_closes_balances(): void
    {
        // EUE-122: Startsaldo ± Zu-/Abflüsse je Zahlart und Monat.
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
            'direction' => 'income', 'kind_type' => 'Honorar', 'e1a_code' => '9040',
            'payment_method' => 'Bankkonto', 'business_share' => 100,
            'amount_cents' => 100000, 'business_amount_cents' => 100000,
        ]);
        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 6, 'day' => 5,
            'direction' => 'expense', 'kind_type' => 'Büro', 'e1a_code' => '9230',
            'payment_method' => 'Bankkonto', 'business_share' => 100,
            'amount_cents' => 25000, 'business_amount_cents' => 25000,
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/payment-reconciliation?year=2026');

        $response->assertOk()->assertJsonCount(2, 'methods');
        $bank = collect($response->json('methods'))->firstWhere('method', 'Bankkonto');
        $this->assertSame(100000, $bank['months'][4]['closing_cents']);
        $this->assertSame(75000, $bank['closing_balance_cents']);
    }

    public function test_income_tax_accepts_mode_and_derives_e1a_e1b_from_entries(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/est/calc', [
                'year' => 2026,
                'profit_cents' => 5000000,
                'mode' => 'business',
                'entries' => [
                    ['direction' => 'income', 'business_amount_cents' => 6000000],
                    ['direction' => 'expense', 'business_amount_cents' => 1000000],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'business');
        $response->assertJsonPath('e1a', 6000000);
        $response->assertJsonPath('e1b', 1000000);
        $response->assertJsonPath('deductions_cents', 1000000);
        $this->assertIsInt($response->json('income_tax_cents'));
        $this->assertIsArray($response->json('stages'));

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/est/calc', [
                'year' => 2026, 'profit_cents' => 0, 'mode' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');
    }

    public function test_foreign_year_is_rejected(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/analytics?year=2027')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }
}
