<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;

/** EUE-056/057: Plausibilitätshinweise bleiben nicht-blockierende Antwortfelder. */
class EurAssetPlausibilityApiTest extends EurBookkeepingTestCase
{
    private function store(string $token): int
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Plausibilität',
            'invoice_no' => 'PL-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 30000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'linear',
        ]);
        $response->assertCreated();

        return (int) $response->json('asset.id');
    }

    public function test_api_exposes_prior_year_plausibility_warnings(): void
    {
        $token = $this->tokenFor();
        $id = $this->store($token);

        EurAssetYear::forCurrentBrand()->where('eur_asset_id', $id)->where('year', 2025)
            ->update(['depreciation_cents' => 12000, 'book_value_cents' => 0]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();

        $asset = collect($response->json('assets'))->firstWhere('id', $id);
        $codes = array_column($asset['warnings'], 'code');

        $this->assertContains('EUE-056', $codes);
        $this->assertContains('EUE-057', $codes);
        $this->assertStringContainsString('erwartet: 100,00 €', implode(' ', array_column($asset['warnings'], 'text')));
        $this->assertStringContainsString('Restbuchwert 2025 fehlt.', implode(' ', array_column($asset['warnings'], 'text')));
    }

    public function test_matching_plan_has_no_plausibility_warning(): void
    {
        $token = $this->tokenFor();
        $id = $this->store($token);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();

        $asset = collect($response->json('assets'))->firstWhere('id', $id);
        $codes = array_column($asset['warnings'], 'code');

        $this->assertNotContains('EUE-056', $codes);
        $this->assertNotContains('EUE-057', $codes);
    }

    public function test_a_completed_switch_does_not_produce_false_plausibility_warnings(): void
    {
        $token = $this->tokenFor();
        $created = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Wechselgut',
            'invoice_no' => 'SW-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 30000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'degressive',
            'degressive_rate' => 2000,
        ]);
        $created->assertCreated();
        $id = (int) $created->json('asset.id');

        $this->withHeaders($this->bearer($token))
            ->postJson("/api/management/eur/assets/{$id}/switch-to-linear", [
                'year' => 2025,
                'reason' => 'Lineare AfA ist wirtschaftlicher.',
            ])
            ->assertOk();

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();

        $asset = collect($response->json('assets'))->firstWhere('id', $id);
        $codes = array_column($asset['warnings'], 'code');

        $this->assertNotContains('EUE-056', $codes);
        $this->assertNotContains('EUE-057', $codes);
        $this->assertSame('linear', $asset['method']);
    }

    public function test_foreign_brand_assets_do_not_enter_the_warning_check(): void
    {
        $token = $this->tokenFor();

        EurAsset::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremdes Gut',
            'invoice_no' => 'F-PL',
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 30000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'linear',
        ]);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $response->assertOk();

        $this->assertSame([], $response->json('assets'));
        $this->assertSame(1, EurAsset::where('brand', self::OTHER_BRAND)->count());
    }
}
