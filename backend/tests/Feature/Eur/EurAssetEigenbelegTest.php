<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Support\Share;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurAuditLog;
use App\Models\EurEntry;
use App\Support\BrandRegistry;

/** D-85: privater Eigenbeleg dokumentiert die fiktive AfA ohne Buchung. */
class EurAssetEigenbelegTest extends EurBookkeepingTestCase
{
    public function test_a_private_eigenbeleg_creates_no_journal_entry_and_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Privates Gut', 25);
        $expected = (new DepreciationCalculator(new RuleValues))->linearPlan(240000, 3, 1)[0]['depreciation_cents'];

        $first = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $first->assertCreated();
        $first->assertJsonPath('already_created', false);
        $first->assertJsonPath('eigenbeleg.eigenbeleg_no', 'EB-'.$asset->id.'-2026');
        $first->assertJsonPath('eigenbeleg.theoretical_depreciation_cents', $expected);
        $first->assertJsonPath('eigenbeleg.usage_percent', 25);
        $first->assertJsonPath('eigenbeleg.eigenbeleg_cents', Share::apply($expected, 25));
        $this->assertNotNull($first->json('eigenbeleg.eigenbeleg_created_at'));

        $this->assertSame(0, EurEntry::forCurrentBrand()->count());
        $this->assertSame(0, EurAssetYear::forCurrentBrand()->whereNotNull('eur_entry_id')->count());
        $this->assertSame(0, EurAssetYear::forCurrentBrand()->where('status', 'booked')->count());
        $this->assertDatabaseHas('eur_audit_logs', ['action' => 'eigenbeleg_created', 'subject_id' => $asset->id]);

        $plan = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets/'.$asset->id.'/plan');
        $plan->assertOk();
        $plan->assertJsonPath('asset.years.0.eigenbeleg_no', 'EB-'.$asset->id.'-2026');
        $plan->assertJsonPath('asset.years.0.eigenbeleg_cents', Share::apply($expected, 25));
        $this->assertArrayNotHasKey('entry', $first->json());

        $row = EurAssetYear::where('eur_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $createdAt = $row->eigenbeleg_created_at?->toIso8601String();

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $second->assertOk();
        $second->assertJsonPath('already_created', true);
        $second->assertJsonPath('eigenbeleg.eigenbeleg_cents', Share::apply($expected, 25));
        $this->assertSame($createdAt, $row->refresh()->eigenbeleg_created_at?->toIso8601String());
        $this->assertSame(0, EurEntry::count());
        $this->assertSame(1, EurAuditLog::where('action', 'eigenbeleg_created')->count());
    }

    public function test_a_business_asset_still_creates_a_deductible_journal_entry(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Betriebliches Gut', 100);

        $response = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/book-depreciation',
            ['year' => 2026],
        );
        $response->assertCreated();
        $this->assertNotNull($response->json('entry.id'));
        $this->assertArrayNotHasKey('eigenbeleg', $response->json());
        $this->assertSame(1, EurEntry::forCurrentBrand()->count());

        $row = EurAssetYear::where('eur_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $this->assertNull($row->eigenbeleg_no);
        $this->assertNull($row->eigenbeleg_cents);
        $this->assertNotNull($row->eur_entry_id);
    }

    private function asset(string $name, int $share): EurAsset
    {
        $asset = EurAsset::create([
            'brand' => BrandRegistry::currentId(),
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => $share,
            'scope' => $share < 50 ? 'private' : 'business',
            'method' => 'linear',
        ]);

        $plan = (new DepreciationCalculator(new RuleValues))->linearPlan(240000, 3, 1);
        $privatePercent = 100 - $share;

        foreach ([0, 1, 2] as $index) {
            $depreciation = (int) $plan[$index]['depreciation_cents'];
            $privateCents = Share::apply($depreciation, $privatePercent);

            EurAssetYear::create([
                'brand' => $asset->brand,
                'eur_asset_id' => $asset->id,
                'year' => 2026 + $index,
                'scope' => $asset->scope,
                'method' => 'linear',
                'depreciation_cents' => $depreciation,
                'book_value_cents' => (int) $plan[$index]['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $depreciation - $privateCents,
                'status' => 'planned',
            ]);
        }

        return $asset;
    }
}
