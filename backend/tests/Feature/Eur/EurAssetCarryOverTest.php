<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Models\EurAuditLog;
use Illuminate\Support\Facades\DB;

/** D-85: idempotenter Jahreswechsel ohne Veränderung bestehender Zeilen. */
class EurAssetCarryOverTest extends EurBookkeepingTestCase
{
    public function test_carry_over_creates_only_missing_rows_and_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Fortgeführt', 100);
        $disposed = $this->asset('Veräußert', 100);
        $disposed->forceFill(['status' => 'disposed', 'disposed_on' => '2026-01-01'])->save();
        $foreign = $this->asset('Fremd', 100, self::OTHER_BRAND);

        foreach ([$asset, $disposed, $foreign] as $subject) {
            EurAssetYear::where('eur_asset_id', $subject->id)->where('year', 2026)->delete();
        }

        // Eine vorhandene Zeile trägt einen bewusst abweichenden Wert; sie
        // darf vom Carry-over nie überschrieben werden.
        EurAssetYear::where('eur_asset_id', $asset->id)->where('year', 2025)->update(['depreciation_cents' => 12345]);

        $first = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets/carry-over', ['year' => 2026]);
        $first->assertCreated();
        $first->assertJsonPath('created', 1);

        $expected = (new DepreciationCalculator(new RuleValues))->linearPlan(240000, 3, 1)[2];
        $created = EurAssetYear::forCurrentBrand()->where('eur_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(2025, (int) $created->carry_over_from_year);
        $this->assertSame('business', $created->scope);
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->eur_entry_id);
        $this->assertSame((int) $expected['depreciation_cents'], (int) $created->depreciation_cents);
        $this->assertSame((int) $expected['book_value_cents'], (int) $created->book_value_cents);
        $this->assertIsInt(DB::table('eur_asset_years')->where('id', $created->id)->value('depreciation_cents'));
        $this->assertIsInt(DB::table('eur_asset_years')->where('id', $created->id)->value('carry_over_from_year'));
        $this->assertNull(DB::table('eur_asset_years')->where('id', $created->id)->value('eigenbeleg_cents'));

        $this->assertSame(12345, (int) DB::table('eur_asset_years')
            ->where('eur_asset_id', $asset->id)->where('year', 2025)->value('depreciation_cents'));
        $this->assertSame(0, EurAssetYear::where('eur_asset_id', $disposed->id)->where('year', 2026)->count());
        $this->assertSame(0, EurAssetYear::where('eur_asset_id', $foreign->id)->where('year', 2026)->count());

        $second = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets/carry-over', ['year' => 2026]);
        $second->assertOk();
        $second->assertJsonPath('created', 0);
        $this->assertSame(3, EurAssetYear::where('eur_asset_id', $asset->id)->count());
        $this->assertSame(2, EurAuditLog::where('action', 'carry_over')->count());
    }

    public function test_private_assets_are_carried_over_into_the_private_directory(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Privat', 25);
        EurAssetYear::where('eur_asset_id', $asset->id)->where('year', 2026)->delete();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets/carry-over', ['year' => 2026]);
        $response->assertCreated();
        $response->assertJsonPath('created', 1);

        $row = EurAssetYear::where('eur_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $this->assertSame('private', $row->scope);
        $this->assertSame(75, (int) DB::table('eur_asset_years')->where('id', $row->id)->value('private_use_percent'));
    }

    public function test_an_unsupported_target_year_is_rejected(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))->postJson('/api/management/eur/assets/carry-over', ['year' => 2027])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }

    private function asset(string $name, int $share, string $brand = 'rp'): EurAsset
    {
        $asset = EurAsset::create([
            'brand' => $brand,
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => $share,
            'scope' => $share < 50 ? 'private' : 'business',
            'method' => 'linear',
        ]);

        foreach ([2024, 2025, 2026] as $year) {
            EurAssetYear::create([
                'brand' => $brand,
                'eur_asset_id' => $asset->id,
                'year' => $year,
                'scope' => $asset->scope,
                'method' => 'linear',
                'depreciation_cents' => 80000,
                'book_value_cents' => 240000 - ($year - 2024) * 80000,
                'private_use_percent' => 100 - $share,
                'private_use_cents' => 0,
                'business_use_cents' => 80000,
                'status' => 'planned',
            ]);
        }

        return $asset;
    }
}
