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
