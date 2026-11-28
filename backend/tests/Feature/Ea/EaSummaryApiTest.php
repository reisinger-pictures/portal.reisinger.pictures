<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaAsset;
use App\Models\EaAssetYear;
use App\Models\EaCustomer;
use App\Models\EaDocument;
use App\Models\EaEntry;
use App\Models\EaProject;
use App\Models\EaSignature;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;

/**
 * EAR · GET /summary — additive Blöcke für die Übersicht und Brand-Trennung.
 */
class EaSummaryApiTest extends EaBookkeepingTestCase
{
    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/management/ea/summary')->assertUnauthorized();
    }

    public function test_summary_keeps_existing_keys_and_adds_the_overview_blocks(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();
        app(SettingResolver::class)->set('ea_sv_mode', 'uv_only');

        $customer = EaCustomer::create(['brand' => $brand, 'name' => 'KUNDE_XYZ']);
        $project = EaProject::create(['brand' => $brand, 'name' => 'PROJEKT_XYZ', 'ea_customer_id' => $customer->id]);

        EaEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 200000, 'business_amount_cents' => 200000,
            'ea_customer_id' => $customer->id, 'ea_project_id' => $project->id,
        ]);
        EaEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'expense',
            'kind_type' => 'SV', 'e1a_code' => '9225', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);
        EaEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 999999, 'business_amount_cents' => 999999,
        ]);

        $asset = EaAsset::create([
            'brand' => $brand, 'name' => 'ASSET_XYZ', 'invoice_no' => 'RE-1', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 100000, 'useful_life_years' => 3,
            'business_share' => 100, 'method' => 'linear',
        ]);
        EaAssetYear::create([
            'brand' => $brand, 'ea_asset_id' => $asset->id, 'year' => 2026, 'method' => 'linear',
            'depreciation_cents' => 20000, 'book_value_cents' => 80000,
            'private_use_percent' => 0, 'private_use_cents' => 5000, 'business_use_cents' => 20000,
        ]);

        EaDocument::create([
            'brand' => $brand, 'year' => 2026, 'original_name' => 'beleg.pdf',
            'storage_path' => 'ea/rp/beleg.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'sha256' => str_repeat('a', 64), 'status' => 'uploaded',
        ]);
        EaDocument::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'original_name' => 'fremd.pdf',
            'storage_path' => 'ea/srp/fremd.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'sha256' => str_repeat('b', 64), 'status' => 'uploaded',
        ]);
        EaSignature::create(['brand' => $brand, 'year' => 2026, 'month' => 5, 'provider' => 'TestSign', 'status' => 'signed']);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/summary?year=2026');
        $response->assertOk();

        foreach (['year', 'entry_count', 'asset_count', 'income_cents', 'expense_cents', 'afa_cents', 'profit_cents'] as $key) {
            $this->assertArrayHasKey($key, $response->json());
        }
        $response->assertJsonPath('income_cents', 200000)
            ->assertJsonPath('profit_cents', 170000)
            ->assertJsonCount(12, 'sv_monthly')
            ->assertJsonPath('sv_monthly.0.kv_cents', 0)
            ->assertJsonPath('sv_monthly.0.pv_cents', 0)
            ->assertJsonPath('sv_monthly.0.svs_cents', 0)
            ->assertJsonPath('sv_monthly.0.uv_cents', 1295)
            ->assertJsonPath('insurance_blocks.3.label', 'Unfallversicherung')
            ->assertJsonPath('afa_card.booking_cents', 20000)
            ->assertJsonPath('afa_card.private_cents', 5000)
            ->assertJsonPath('afa_card.residual_cents', 80000)
            ->assertJsonPath('by_customer.0.customer', 'KUNDE_XYZ')
            ->assertJsonPath('by_customer.0.project', 'PROJEKT_XYZ')
            ->assertJsonPath('by_customer.0.revenue_cents', 200000)
            ->assertJsonPath('by_customer.0.margin_cents', 200000)
            ->assertJsonPath('open_documents', 1)
            ->assertJsonPath('signature_status', 'signed');

        $this->assertStringNotContainsString('999999', (string) json_encode($response->json()));
    }

    public function test_booked_9225_is_removed_from_expenses_outside_the_uv_mode(): void
    {
        // EUE-115: bei SV-Berechnung darf 9225 den Gewinn nicht zusätzlich mindern.
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();
        app(SettingResolver::class)->set('ea_sv_mode', 'gsvg');

        EaEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 200000, 'business_amount_cents' => 200000,
        ]);
        EaEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'expense',
            'kind_type' => 'SV', 'e1a_code' => '9225', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);
        EaAsset::create([
            'brand' => $brand, 'name' => 'ASSET_115', 'invoice_no' => 'RE-115', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 100000, 'useful_life_years' => 3,
            'business_share' => 100, 'method' => 'linear',
        ]);
        EaAssetYear::create([
            'brand' => $brand, 'ea_asset_id' => EaAsset::where('name', 'ASSET_115')->firstOrFail()->id,
            'year' => 2026, 'method' => 'linear', 'depreciation_cents' => 20000,
            'book_value_cents' => 80000, 'private_use_percent' => 0,
            'private_use_cents' => 0, 'business_use_cents' => 20000,
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/summary?year=2026');
        $response->assertOk()
            ->assertJsonPath('expense_cents', 10000)
            ->assertJsonPath('booked_sv_cents', 10000)
            ->assertJsonPath('profit_cents', 180000);
    }

    public function test_unknown_year_is_rejected(): void
    {
        $this->withHeaders($this->bearer($this->tokenFor()))
            ->getJson('/api/management/ea/summary?year=2025')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }

    /**
     * Ohne `year` gilt das laufende Buchungsjahr (`EaYear::currentOrLast()`),
     * nicht ein Literal: im Buchungsjahr 2027 ist 2027 der Default.
     */
    public function test_summary_defaults_to_the_current_booking_year(): void
    {
        $this->travelTo('2027-02-01 09:00:00');
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/summary')
            ->assertOk()
            ->assertJsonPath('year', 2027);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/summary?year=2026')
            ->assertOk()
            ->assertJsonPath('year', 2026);
    }
}
