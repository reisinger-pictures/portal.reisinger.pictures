<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurEntry;
use App\Models\EurHour;
use App\Models\EurMonthlyReport;
use App\Models\EurSignature;
use App\Models\EurWarehouseEntry;
use App\Support\BrandRegistry;

class EurMonthApiTest extends EurBookkeepingTestCase
{
    public function test_month_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/month?year=2026&month=5')->assertUnauthorized();
    }

    public function test_month_returns_the_contract_shape_and_cent_totals(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
            'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
            'amount_cents' => 12000, 'business_amount_cents' => 12000,
        ]);
        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 6,
            'direction' => 'expense', 'kind_type' => 'Software', 'business_share' => 50,
            'amount_cents' => 4000, 'business_amount_cents' => 2000,
        ]);
        EurWarehouseEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 7,
            'description' => 'Papier', 'amount_cents' => 1500,
        ]);
        EurHour::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 8, 'minutes' => 90,
        ]);
        EurMonthlyReport::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'status' => 'closed', 'closed_at' => now(),
        ]);
        EurSignature::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5,
            'provider' => 'test-provider', 'status' => 'signed',
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/month?year=2026&month=5');

        $response->assertOk();
        $response->assertJsonPath('year', 2026);
        $response->assertJsonPath('month', 5);
        foreach (['lines', 'totals', 'checks', 'social_insurance', 'income_tax', 'report', 'signature'] as $key) {
            $this->assertArrayHasKey($key, $response->json());
        }
        $response->assertJsonPath('totals.income_cents', 12000);
        $response->assertJsonPath('totals.expense_cents', 2000);
        $response->assertJsonPath('totals.warehouse_cents', 1500);
        $response->assertJsonPath('totals.hours_minutes', 90);
        $response->assertJsonPath('report.status', 'closed');
        $response->assertJsonPath('signature.status', 'signed');
        $response->assertJsonPath('signature.is_test', true);
    }

    /**
     * Regressionsfall für Issue #52: `lines[].warnings`/`lines[].hints` sind
     * Zeichenketten (Check-Nachricht bzw. -Hinweis), keine Check-Objekte. Ein
     * Objekt ließ `withWarning()` im Monatspanel mit
     * `text.startsWith is not a function` abstürzen.
     */
    public function test_month_line_warnings_and_hints_are_check_messages_as_strings(): void
    {
        $token = $this->tokenFor();

        EurEntry::create([
            'brand' => BrandRegistry::currentId(), 'year' => 2026, 'month' => 5, 'day' => null,
            'direction' => 'expense', 'kind_type' => 'Software', 'category' => 'IT',
            'description' => 'Abo ohne Tag', 'business_share' => 100,
            'amount_cents' => 1000, 'business_amount_cents' => 1000, 'receipt_no' => 'R-52',
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/month?year=2026&month=5');

        $response->assertOk();

        $line = null;
        foreach ($response->json('lines') as $candidate) {
            if ($candidate['description'] === 'Abo ohne Tag') {
                $line = $candidate;
                break;
            }
        }
        $this->assertNotNull($line, 'Die Buchung fehlt in den Monatszeilen.');

        foreach (['warnings', 'hints'] as $key) {
            $this->assertIsArray($line[$key]);
            foreach ($line[$key] as $value) {
                $this->assertIsString($value, sprintf('lines[].%s darf nur Strings enthalten.', $key));
            }
        }

        $this->assertContains('⚠ Tag fehlt.', $line['warnings']);
        $this->assertContains('💡 Bitte den Tag der Buchung (1–31) ergänzen.', $line['hints']);
    }

    public function test_month_defaults_to_2026_and_rejects_2027(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/month?month=1')
            ->assertOk()
            ->assertJsonPath('year', 2026);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/month?year=2027&month=1')
            ->assertUnprocessable();
    }

    public function test_month_close_is_idempotent_and_audited_once(): void
    {
        $token = $this->tokenFor();

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/close', ['year' => 2026, 'month' => 5, 'note' => 'Abgeschlossen']);
        $first->assertOk();
        $first->assertJsonPath('report.status', 'closed');

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/close', ['year' => 2026, 'month' => 5, 'note' => 'Erneut']);
        $second->assertOk();
        $second->assertJsonPath('report.status', 'closed');

        $this->assertDatabaseCount('eur_monthly_reports', 1);
        $this->assertDatabaseHas('eur_monthly_reports', ['year' => 2026, 'month' => 5, 'status' => 'closed']);
        $this->assertDatabaseCount('eur_audit_logs', 1);
        $this->assertDatabaseHas('eur_audit_logs', ['action' => 'month_closed', 'subject_type' => 'eur_monthly_report']);
    }

    public function test_entry_accepts_optional_day_category_description_and_exposes_checks(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/entries', [
                'year' => 2025,
                'month' => 5,
                'day' => 31,
                'direction' => 'expense',
                'kind_type' => 'Software',
                'category' => 'IT',
                'description' => 'Abo',
                'business_share' => 100,
                'amount_cents' => 1000,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('entry.day', 31);
        $response->assertJsonPath('entry.category', 'IT');
        $response->assertJsonPath('entry.description', 'Abo');
        $this->assertIsArray($response->json('entry.checks'));

        $listed = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/entries?year=2025');

        $listed->assertOk();
        $this->assertIsArray($listed->json('entries.0.checks'));
    }

    public function test_month_close_requires_authentication_and_a_valid_year(): void
    {
        $this->postJson('/api/management/eur/month/close', ['year' => 2026, 'month' => 5])->assertUnauthorized();

        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/month/close', ['year' => 2027, 'month' => 5])
            ->assertUnprocessable();
    }
}
