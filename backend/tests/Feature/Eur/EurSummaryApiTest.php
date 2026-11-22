<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurCustomer;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Models\EurProject;
use App\Models\EurSignature;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;

/**
 * EAR · GET /summary — additive Blöcke für die Übersicht und Brand-Trennung.
 */
class EurSummaryApiTest extends EurBookkeepingTestCase
{
    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/management/eur/summary')->assertUnauthorized();
    }

    public function test_summary_keeps_existing_keys_and_adds_the_overview_blocks(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();
        app(SettingResolver::class)->set('eur_sv_mode', 'uv_only');

        $customer = EurCustomer::create(['brand' => $brand, 'name' => 'KUNDE_XYZ']);
        $project = EurProject::create(['brand' => $brand, 'name' => 'PROJEKT_XYZ', 'eur_customer_id' => $customer->id]);

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 200000, 'business_amount_cents' => 200000,
            'eur_customer_id' => $customer->id, 'eur_project_id' => $project->id,
        ]);
        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'expense',
            'kind_type' => 'SV', 'e1a_code' => '9225', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);
        EurEntry::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 999999, 'business_amount_cents' => 999999,
        ]);

        $asset = EurAsset::create([
            'brand' => $brand, 'name' => 'ASSET_XYZ', 'invoice_no' => 'RE-1', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 100000, 'useful_life_years' => 3,
            'business_share' => 100, 'method' => 'linear',
        ]);
        EurAssetYear::create([
            'brand' => $brand, 'eur_asset_id' => $asset->id, 'year' => 2026, 'method' => 'linear',
            'depreciation_cents' => 20000, 'book_value_cents' => 80000,
            'private_use_percent' => 0, 'private_use_cents' => 5000, 'business_use_cents' => 20000,
        ]);

        EurDocument::create([
            'brand' => $brand, 'year' => 2026, 'original_name' => 'beleg.pdf',
            'storage_path' => 'eur/rp/beleg.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'sha256' => str_repeat('a', 64), 'status' => 'uploaded',
        ]);
        EurDocument::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026, 'original_name' => 'fremd.pdf',
            'storage_path' => 'eur/srp/fremd.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'sha256' => str_repeat('b', 64), 'status' => 'uploaded',
        ]);
        EurSignature::create(['brand' => $brand, 'year' => 2026, 'month' => 5, 'provider' => 'TestSign', 'status' => 'signed']);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/summary?year=2026');
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
        app(SettingResolver::class)->set('eur_sv_mode', 'gsvg');

        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'income',
            'kind_type' => 'Honorar', 'e1a_code' => '9040', 'business_share' => 100,
            'amount_cents' => 200000, 'business_amount_cents' => 200000,
        ]);
        EurEntry::create([
            'brand' => $brand, 'year' => 2026, 'month' => 5, 'direction' => 'expense',
            'kind_type' => 'SV', 'e1a_code' => '9225', 'business_share' => 100,
            'amount_cents' => 10000, 'business_amount_cents' => 10000,
        ]);
        EurAsset::create([
            'brand' => $brand, 'name' => 'ASSET_115', 'invoice_no' => 'RE-115', 'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15', 'cost_cents' => 100000, 'useful_life_years' => 3,
            'business_share' => 100, 'method' => 'linear',
        ]);
        EurAssetYear::create([
            'brand' => $brand, 'eur_asset_id' => EurAsset::where('name', 'ASSET_115')->firstOrFail()->id,
            'year' => 2026, 'method' => 'linear', 'depreciation_cents' => 20000,
            'book_value_cents' => 80000, 'private_use_percent' => 0,
            'private_use_cents' => 0, 'business_use_cents' => 20000,
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/summary?year=2026');
        $response->assertOk()
            ->assertJsonPath('expense_cents', 10000)
            ->assertJsonPath('booked_sv_cents', 10000)
            ->assertJsonPath('profit_cents', 180000);
    }

    public function test_unknown_year_is_rejected(): void
    {
        $this->withHeaders($this->bearer($this->tokenFor()))
            ->getJson('/api/management/eur/summary?year=2027')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }
}
