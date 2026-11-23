<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetYear;
use Illuminate\Testing\TestResponse;

/** D-85: getrennte Verzeichnisse, serverseitige Einordnung, Markengrenze. */
class EurAssetDirectoryTest extends EurBookkeepingTestCase
{
    public function test_scope_is_derived_from_the_business_share_and_inherited_by_the_plan(): void
    {
        $token = $this->tokenFor();

        $private = $this->storeAsset($token, 25);
        $private->assertCreated();
        $private->assertJsonPath('asset.scope', 'private');
        $privateId = (int) $private->json('asset.id');

        foreach ($private->json('asset.years') as $year) {
            $this->assertSame('private', $year['scope']);
        }
        $this->assertSame(3, EurAssetYear::forCurrentBrand()->where('eur_asset_id', $privateId)->where('scope', 'private')->count());

        $business = $this->storeAsset($token, 50);
        $business->assertCreated();
        $business->assertJsonPath('asset.scope', 'business');
        $businessId = (int) $business->json('asset.id');

        foreach ($business->json('asset.years') as $year) {
            $this->assertSame('business', $year['scope']);
        }
        $this->assertSame(3, EurAssetYear::forCurrentBrand()->where('eur_asset_id', $businessId)->where('scope', 'business')->count());
    }

    public function test_a_client_cannot_set_the_scope(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', $this->payload(80) + ['scope' => 'private'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', $this->payload(25) + ['scope' => 'business'])
            ->assertUnprocessable();
    }

    public function test_the_index_filters_by_scope_and_stays_brand_scoped(): void
    {
        $token = $this->tokenFor();
        $this->storeAsset($token, 25)->assertCreated();
        $this->storeAsset($token, 75)->assertCreated();

        EurAsset::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremdes Gut',
            'invoice_no' => 'F-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 100000,
            'useful_life_years' => 3,
            'business_share' => 25,
            'scope' => 'private',
            'method' => 'linear',
        ]);

        $all = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets');
        $all->assertOk();
        $this->assertCount(2, $all->json('assets'));

        $private = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets?scope=private');
        $private->assertOk();
        $this->assertCount(1, $private->json('assets'));
        $this->assertSame('privat', $private->json('assets.0.name'));
        $this->assertSame('private', $private->json('assets.0.scope'));

        $business = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets?scope=business');
        $business->assertOk();
        $this->assertCount(1, $business->json('assets'));
        $this->assertSame('betrieblich', $business->json('assets.0.name'));

        $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets?scope=unknown')->assertUnprocessable();
    }

    public function test_a_private_asset_uses_linear_depreciation_but_cannot_use_degressive(): void
    {
        $token = $this->tokenFor();

        $linear = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', $this->payload(25));
        $linear->assertCreated();
        $linear->assertJsonPath('asset.scope', 'private');
        $linear->assertJsonPath('asset.method', 'linear');

        $degressive = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets',
            array_merge($this->payload(25), ['method' => 'degressiv', 'degressive_rate' => 3000]),
        );
        $degressive->assertUnprocessable();

        $this->assertSame(1, EurAsset::forCurrentBrand()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $share): array
    {
        return [
            'name' => $share < 50 ? 'privat' : 'betrieblich',
            'invoice_no' => 'R-'.$share,
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => $share,
            'method' => 'linear',
        ];
    }

    private function storeAsset(string $token, int $share): TestResponse
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', $this->payload($share));
        $response->assertCreated();

        return $response;
    }
}
