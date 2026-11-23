<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Models\EurProject;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTokenFactory;
use Tests\TestCase;

/**
 * EAR · API-Vertrag (Portal #24, #26, #27).
 *
 * Abgedeckt: Authentifizierung, Rollen, Brand-Trennung (fremde IDs → 404),
 * Validierung (Jahr 2026, 5-%-Schritte, ganze Cent), Audit ohne Inhalte,
 * Anlagenplan und DEG-Vergleich.
 */
class EurApiTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'srp';

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
    }

    private function tokenFor(string $role): string
    {
        $user = User::factory()->create(['brand' => BrandRegistry::currentId()]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return AuthTokenFactory::forUser($user);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_reference_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/reference')->assertUnauthorized();
    }

    public function test_reference_endpoint_returns_integer_catalogues(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference');

        $response->assertOk();
        $response->assertJsonPath('business_year', 2026);
        $response->assertJsonPath('units.money', 'cents');
        $response->assertJsonCount(34, 'expense_kinds');
        $response->assertJsonCount(9, 'income_kinds');
        $response->assertJsonCount(7, 'vat_kinds');
        $response->assertJsonCount(174, 'travel.countries');
        $response->assertJsonPath('travel.domestic.day_cents', 3000);
        $response->assertJsonPath('travel.domestic.night_cents', 1700);
        $response->assertJsonCount(5, 'income_tax.stages');
        $this->assertSame(808500, $response->json('social_insurance.amounts_cents.max_bgl'));
        // EUE-051: GWG-Grenze aus dem gepinnten Katalog (1.000 € = 100000 ct).
        $response->assertJsonPath('assets.gwg_limit_cents', 100000);
    }

    /**
     * EUE-006: die Referenz liefert echte, brand-scope Projekt-IDs, damit die
     * Buchung `eur_project_id` setzen kann (die Katalogliste `projects` bleibt
     * nur eine Anzeigehilfe).
     */
    public function test_reference_exposes_brand_scoped_project_ids_for_booking(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);
        $project = EurProject::create(['brand' => BrandRegistry::currentId(), 'name' => 'Projekt A']);
        EurProject::create(['brand' => self::OTHER_BRAND, 'name' => 'Fremdprojekt']);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference');

        $response->assertOk();
        $response->assertJsonCount(1, 'eur_projects');
        $response->assertJsonPath('eur_projects.0.id', $project->id);
        $response->assertJsonPath('eur_projects.0.name', 'Projekt A');
    }

    /**
     * EUE-029: der Verwendungszähler zählt echte Journalbuchungen des Brands,
     * nicht die statischen Katalogarten.
     */
    public function test_reference_exposes_journal_usage_counts(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);
        $brand = BrandRegistry::currentId();

        // Echte Katalog-Typen aus tables.json (`ausgaben_arten`), damit der
        // Vertrag kind_type (Buchung) ↔ type (Katalog) mitgeprüft wird.
        foreach (['Wareneinkäufe', 'Wareneinkäufe', 'Rohstoffe'] as $type) {
            EurEntry::create([
                'brand' => $brand, 'year' => 2026, 'month' => 5, 'day' => 5,
                'direction' => 'expense', 'kind_type' => $type, 'business_share' => 100,
                'amount_cents' => 10000, 'business_amount_cents' => 10000,
            ]);
        }

        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'day' => 5,
            'direction' => 'expense', 'kind_type' => 'Wareneinkäufe', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference');

        $response->assertOk();
        $response->assertJsonPath('usage_counts.expense.Wareneinkäufe', 2);
        $response->assertJsonPath('usage_counts.expense.Rohstoffe', 1);
        $response->assertJsonPath('usage_counts.income', []);
    }

    public function test_photographer_is_rejected_by_management_scope(): void
    {
        $token = $this->tokenFor(UserRole::PHOTOGRAPHER->value);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference')
            ->assertForbidden();
    }

    public function test_creating_an_entry_computes_the_business_amount_and_writes_an_audit_row(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/entries', [
            'year' => 2026,
            'month' => 5,
            'direction' => 'expense',
            'kind_type' => 'Software',
            'e1a_code' => '9190',
            'business_share' => 60,
            'amount_cents' => 10000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('entry.business_amount_cents', 6000);
        $response->assertJsonPath('entry.amount_cents', 10000);

        $this->assertDatabaseHas('eur_entries', [
            'business_share' => 60,
            'amount_cents' => 10000,
            'business_amount_cents' => 6000,
        ]);
        $this->assertDatabaseHas('eur_audit_logs', [
            'action' => 'created',
            'subject_type' => 'eur_entry',
        ]);

        $audit = EurAuditLog::query()->firstOrFail();
        $this->assertIsArray($audit->payload);
        $this->assertSame(6000, $audit->payload['business_amount_cents']);
    }

    public function test_entry_validation_rejects_foreign_year_and_non_five_percent_share(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $base = [
            'year' => 2026,
            'month' => 5,
            'direction' => 'expense',
            'kind_type' => 'Software',
            'business_share' => 60,
            'amount_cents' => 10000,
        ];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/entries', array_merge($base, ['year' => 2027]))
            ->assertUnprocessable();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/entries', array_merge($base, ['business_share' => 53]))
            ->assertUnprocessable();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/entries', array_merge($base, ['amount_cents' => 12.5]))
            ->assertUnprocessable();
    }

    public function test_foreign_brand_entry_is_not_addressable(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $foreign = EurEntry::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026,
            'month' => 1,
            'direction' => 'income',
            'kind_type' => 'Dienstleistung',
            'business_share' => 100,
            'amount_cents' => 5000,
            'business_amount_cents' => 5000,
        ]);

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/eur/entries/{$foreign->id}", [
                'year' => 2026,
                'month' => 2,
                'direction' => 'income',
                'kind_type' => 'Dienstleistung',
                'business_share' => 100,
                'amount_cents' => 5000,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('eur_entries', ['id' => $foreign->id, 'month' => 1]);
    }

    public function test_summary_sums_integer_cents(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        EurEntry::create([
            'brand' => BrandRegistry::currentId(), 'year' => 2026, 'month' => 1,
            'direction' => 'income', 'kind_type' => 'Dienstleistung', 'business_share' => 100,
            'amount_cents' => 100000, 'business_amount_cents' => 100000,
        ]);
        EurEntry::create([
            'brand' => BrandRegistry::currentId(), 'year' => 2026, 'month' => 1,
            'direction' => 'expense', 'kind_type' => 'Software', 'business_share' => 50,
            'amount_cents' => 20000, 'business_amount_cents' => 10000,
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/summary?year=2026');

        $response->assertOk();
        $response->assertJsonPath('income_cents', 100000);
        $response->assertJsonPath('expense_cents', 10000);
        $response->assertJsonPath('profit_cents', 90000);
    }

    public function test_asset_plan_uses_half_year_rule_and_private_share(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Kamera',
            'invoice_no' => 'R-1',
            'supplier' => 'Fotohandel',
            'purchase_date' => '2026-08-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 80,
            'method' => 'linear',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('asset.years.0.depreciation_cents', 40000);
        $response->assertJsonPath('asset.years.0.private_use_percent', 20);
        $response->assertJsonPath('asset.years.0.private_use_cents', 8000);
        $response->assertJsonPath('asset.years.0.business_use_cents', 32000);
    }

    public function test_degressive_comparison_matches_the_pinned_example(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->getJson(
            '/api/management/eur/assets/compare?cost_cents=2000000&useful_life_years=10&degressive_rate=3000&acquisition_month=1'
        );

        $response->assertOk();
        $response->assertJsonPath('switch_recommended_year', 8);
        $response->assertJsonPath('switch_advantage_cents', 25804);
        $response->assertJsonPath('degressive.0.depreciation_cents', 600000);
        $response->assertJsonPath('linear.0.depreciation_cents', 200000);
    }

    public function test_switch_to_linear_requires_a_reason_and_is_allowed_only_once(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $tokenResponse = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Drucker',
            'invoice_no' => 'R-2',
            'supplier' => 'Technik',
            'purchase_date' => '2026-01-10',
            'cost_cents' => 2000000,
            'useful_life_years' => 10,
            'business_share' => 100,
            'method' => 'degressive',
            'degressive_rate' => 3000,
        ]);

        $tokenResponse->assertCreated();
        $id = $tokenResponse->json('asset.id');

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/assets/{$id}/switch-to-linear", ['year' => 2026])
            ->assertUnprocessable();

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/assets/{$id}/switch-to-linear", ['year' => 2033, 'reason' => 'Linear ist ab Jahr 8 günstiger'])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/assets/{$id}/switch-to-linear", ['year' => 2034, 'reason' => 'Nochmal'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('eur_audit_logs', ['action' => 'switched_to_linear', 'subject_type' => 'eur_asset']);
    }

    public function test_vat_calculation_returns_the_eight_kennzahlen_and_zahllast(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/vat/calc', [
            'year' => 2026,
            'month' => 5,
            'small_business_income_cents' => 125000,
            'reverse_charge_cents' => 2000,
            'ig_bases_cents' => ['20' => 100000, '10' => 50000, '13' => 30000, '49' => 10000],
        ]);

        $response->assertOk();
        $response->assertJsonPath('kz.000', 125000);
        $response->assertJsonPath('kz.016', 125000);
        $response->assertJsonPath('kz.057', 2000);
        $response->assertJsonPath('kz.070', 190000);
        $response->assertJsonPath('kz.zahllast', 31390);
    }

    public function test_vat_calculation_rejects_a_foreign_year(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/vat/calc', [
            'year' => 2027,
            'month' => 5,
        ])->assertUnprocessable();
    }

    public function test_supported_years_are_accepted_by_summary_and_vat(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        foreach ([2024, 2025, 2026] as $year) {
            $this->withHeaders($this->bearer($token))
                ->getJson('/api/management/eur/summary?year='.$year)
                ->assertOk()
                ->assertJsonPath('year', $year);

            $this->withHeaders($this->bearer($token))
                ->postJson('/api/management/eur/vat/calc', ['year' => $year, 'month' => 5])
                ->assertOk()
                ->assertJsonPath('year', $year);
        }
    }

    public function test_travel_calculation_uses_pinned_rates_and_the_business_share(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/travel/calc', [
            'year' => 2026,
            'vehicle' => 'Privatauto',
            'kilometres' => 100,
            'passengers' => 0,
            'country' => 'Österreich',
            'days' => 2,
            'meals' => 0,
            'with_receipts' => true,
            'business_share' => 50,
        ]);

        $response->assertOk();
        $response->assertJsonPath('kilometre_cents', 5000);
        $response->assertJsonPath('day_cents', 6000);
        $response->assertJsonPath('night_cents', 3400);
        $response->assertJsonPath('total_cents', 14400);
        $response->assertJsonPath('business_cents', 7200);
    }

    public function test_travel_calculation_rejects_unpinned_years_without_a_server_error(): void
    {
        // EUE-063/064: das API-erlaubte Jahr 2025 darf keinen unkontrollierten
        // HTTP 500 mehr erzeugen. Der km-Satz wird aus YearTables aufgelöst
        // (30.06./01.07.). Da nur die 2026er-Ländertabelle gepinnt ist (EUE-013),
        // antwortet die API mit einer klaren 422 statt mit einem 500.
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/travel/calc', [
            'year' => 2025,
            'month' => 6,
            'vehicle' => 'Motorrad',
            'kilometres' => 100,
            'passengers' => 0,
            'country' => 'Österreich',
            'days' => 1,
            'meals' => 0,
            'with_receipts' => true,
            'business_share' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Für das Reisejahr 2025 ist keine Ländertabelle gepinnt; verfügbar ist 2026.');
        $this->assertStringNotContainsString('unlesbarer Parameterwert', json_encode($response->json()));
    }

    public function test_income_tax_calculation_applies_allowance_and_tariff(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/est/calc', [
            'year' => 2026,
            'profit_cents' => 5000000,
        ]);

        $response->assertOk();
        $response->assertJsonPath('basic_allowance_cents', 495000);
        $response->assertJsonPath('taxable_cents', 4505000);
        $this->assertGreaterThan(0, $response->json('income_tax_cents'));
        $this->assertCount(5, $response->json('stages'));
    }

    public function test_travel_calculation_rejects_an_unknown_country(): void
    {
        $token = $this->tokenFor(UserRole::ADMIN->value);

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/travel/calc', [
            'year' => 2026,
            'vehicle' => 'Privatauto',
            'kilometres' => 100,
            'country' => 'Atlantis',
            'days' => 2,
            'meals' => 0,
            'with_receipts' => true,
            'business_share' => 50,
        ])->assertUnprocessable();
    }
}
