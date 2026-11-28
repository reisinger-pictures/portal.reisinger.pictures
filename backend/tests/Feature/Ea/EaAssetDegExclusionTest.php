<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use Illuminate\Testing\TestResponse;

/** DEG-006/007/008: Ausschlüsse der degressiven AfA an der Schreibgrenze. */
class EaAssetDegExclusionTest extends EaBookkeepingTestCase
{
    /** @param array<string, mixed> $extra */
    private function create(array $extra): TestResponse
    {
        return $this->withHeaders($this->bearer($this->tokenFor()))->postJson('/api/management/ea/assets', [
            'name' => 'Prüfling',
            'invoice_no' => 'R-DEG',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'degressive',
            'degressive_rate' => 3000,
            ...$extra,
        ]);
    }

    public function test_deg_006_rejects_used_goods_only_for_the_degressive_method(): void
    {
        $this->create(['is_used' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Degressive AfA ist für gebrauchte Wirtschaftsgüter nicht zulässig.');

        $this->create(['is_used' => true, 'method' => 'linear', 'degressive_rate' => null])
            ->assertCreated();

        $this->assertDatabaseCount('ea_assets', 1);
    }

    public function test_deg_007_requires_a_pinned_exception_and_stores_it(): void
    {
        $this->create(['is_immaterial' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Degressive AfA ist für immaterielle Wirtschaftsgüter nur mit einer dokumentierten Ausnahme zulässig.');

        $this->create(['is_immaterial' => true, 'immaterial_exception' => 'marketing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('immaterial_exception');

        $created = $this->create(['is_immaterial' => true, 'immaterial_exception' => 'digitalisation'])
            ->assertCreated()
            ->json('asset');
        $this->assertTrue($created['is_immaterial']);
        $this->assertSame('digitalisation', $created['immaterial_exception']);

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->getJson('/api/management/ea/assets')
            ->assertOk()
            ->assertJsonPath('assets.0.immaterial_exception', 'digitalisation');
    }

    public function test_deg_008_excludes_special_depreciation_but_allows_electric_vehicles(): void
    {
        $this->create(['special_depreciation' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Degressive AfA ist bei gesetzlicher Sonder-AfA nicht zulässig.');

        $electric = $this->create([
            'special_depreciation' => true,
            'is_electric_vehicle' => true,
        ])->assertCreated()->json('asset');
        $this->assertTrue($electric['special_depreciation']);
        $this->assertTrue($electric['is_electric_vehicle']);

        $this->create(['special_depreciation' => true, 'method' => 'linear', 'degressive_rate' => null])
            ->assertCreated();

        $this->assertDatabaseCount('ea_assets', 2);
    }

    public function test_existing_assets_without_flags_remain_degressively_valid(): void
    {
        $this->create([])->assertCreated();

        $this->withHeaders($this->bearer($this->tokenFor()))
            ->getJson('/api/management/ea/assets')
            ->assertOk()
            ->assertJsonPath('assets.0.is_used', false)
            ->assertJsonPath('assets.0.is_immaterial', false)
            ->assertJsonPath('assets.0.special_depreciation', false)
            ->assertJsonPath('assets.0.is_electric_vehicle', false)
            ->assertJsonPath('assets.0.immaterial_exception', null);
    }
}
