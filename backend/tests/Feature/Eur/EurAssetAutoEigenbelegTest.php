<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Eur\Support\Share;
use App\Models\EurAssetYear;
use App\Models\EurEntry;

/** EUE-055/D-85: der private Eigenbeleg entsteht ohne Benutzeraktion. */
class EurAssetAutoEigenbelegTest extends EurBookkeepingTestCase
{
    public function test_a_private_asset_gets_all_planned_eigenbelege_at_creation(): void
    {
        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Objektiv 35mm',
            'invoice_no' => 'OBJ-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'method' => 'linear',
        ]);
        $response->assertCreated();
        $id = (int) $response->json('asset.id');

        $years = EurAssetYear::forCurrentBrand()->where('eur_asset_id', $id)->orderBy('year')->get();
        $this->assertCount(3, $years);

        foreach ($years as $year) {
            $this->assertNotNull($year->eigenbeleg_created_at);
            $this->assertSame('EB-'.$id.'-'.$year->year, $year->eigenbeleg_no);
            $this->assertSame(70, (int) $year->private_use_percent);
            $this->assertSame(30, (int) $year->usage_percent);
            $this->assertSame(
                Share::apply((int) $year->depreciation_cents, 30),
                (int) $year->eigenbeleg_cents,
            );
        }

        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
        $this->assertSame(0, EurAssetYear::forCurrentBrand()->where('status', 'booked')->count());
    }

    public function test_a_business_asset_gets_no_eigenbeleg(): void
    {
        $token = $this->tokenFor();
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Betriebliches Gut',
            'invoice_no' => 'BG-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'method' => 'linear',
        ]);
        $response->assertCreated();

        $this->assertSame(0, EurAssetYear::forCurrentBrand()
            ->whereNotNull('eigenbeleg_created_at')->count());
    }

    public function test_the_explicit_endpoint_reports_an_auto_created_eigenbeleg_as_already_created(): void
    {
        $token = $this->tokenFor();
        $created = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Privates Gut',
            'invoice_no' => 'PG-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 25,
            'method' => 'linear',
        ]);
        $created->assertCreated();
        $id = (int) $created->json('asset.id');

        $row = EurAssetYear::forCurrentBrand()->where('eur_asset_id', $id)->where('year', 2026)->firstOrFail();
        $createdAt = $row->eigenbeleg_created_at?->toIso8601String();

        $response = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$id.'/book-depreciation',
            ['year' => 2026],
        );
        $response->assertOk();
        $response->assertJsonPath('already_created', true);
        $response->assertJsonPath('eigenbeleg.eigenbeleg_cents', (int) $row->eigenbeleg_cents);
        $this->assertSame($createdAt, $row->refresh()->eigenbeleg_created_at?->toIso8601String());
        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
    }

    public function test_carry_over_creates_a_private_eigenbeleg_without_user_action(): void
    {
        $token = $this->tokenFor();
        $created = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets', [
            'name' => 'Fortgeführtes Privatgut',
            'invoice_no' => 'FP-1',
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 30,
            'method' => 'linear',
        ]);
        $created->assertCreated();
        $id = (int) $created->json('asset.id');

        EurAssetYear::forCurrentBrand()->where('eur_asset_id', $id)->where('year', 2026)->delete();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/assets/carry-over', ['year' => 2026])
            ->assertCreated();

        $row = EurAssetYear::forCurrentBrand()->where('eur_asset_id', $id)->where('year', 2026)->firstOrFail();
        $this->assertSame('EB-'.$id.'-2026', $row->eigenbeleg_no);
        $this->assertNotNull($row->eigenbeleg_created_at);
        $this->assertSame(30, (int) $row->usage_percent);
        $this->assertSame(Share::apply((int) $row->depreciation_cents, 30), (int) $row->eigenbeleg_cents);
        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
    }
}
