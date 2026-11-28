<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Ea\Domain\DepreciationCalculator;
use App\Ea\Parity\RuleValues;
use App\Models\EaAsset;
use App\Models\EaAssetYear;
use App\Models\EaAuditLog;
use Illuminate\Support\Facades\DB;

/** D-85: idempotenter Jahreswechsel ohne Veränderung bestehender Zeilen. */
class EaAssetCarryOverTest extends EaBookkeepingTestCase
{
    public function test_carry_over_creates_only_missing_rows_and_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Fortgeführt', 100);
        $disposed = $this->asset('Veräußert', 100);
        $disposed->forceFill(['status' => 'disposed', 'disposed_on' => '2026-01-01'])->save();
        $foreign = $this->asset('Fremd', 100, self::OTHER_BRAND);

        foreach ([$asset, $disposed, $foreign] as $subject) {
            EaAssetYear::where('ea_asset_id', $subject->id)->where('year', 2026)->delete();
        }

        // Eine vorhandene Zeile trägt einen bewusst abweichenden Wert; sie
        // darf vom Carry-over nie überschrieben werden.
        EaAssetYear::where('ea_asset_id', $asset->id)->where('year', 2025)->update(['depreciation_cents' => 12345]);

        $first = $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/assets/carry-over', ['year' => 2026]);
        $first->assertCreated();
        $first->assertJsonPath('created', 1);

        $expected = (new DepreciationCalculator(new RuleValues))->linearPlan(240000, 3, 1)[2];
        $created = EaAssetYear::forCurrentBrand()->where('ea_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $this->assertSame(2025, (int) $created->carry_over_from_year);
        $this->assertSame('business', $created->scope);
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->ea_entry_id);
        $this->assertSame((int) $expected['depreciation_cents'], (int) $created->depreciation_cents);
        $this->assertSame((int) $expected['book_value_cents'], (int) $created->book_value_cents);
        $this->assertIsInt(DB::table('ea_asset_years')->where('id', $created->id)->value('depreciation_cents'));
        $this->assertIsInt(DB::table('ea_asset_years')->where('id', $created->id)->value('carry_over_from_year'));
        $this->assertNull(DB::table('ea_asset_years')->where('id', $created->id)->value('eigenbeleg_cents'));

        $this->assertSame(12345, (int) DB::table('ea_asset_years')
            ->where('ea_asset_id', $asset->id)->where('year', 2025)->value('depreciation_cents'));
        $this->assertSame(0, EaAssetYear::where('ea_asset_id', $disposed->id)->where('year', 2026)->count());
        $this->assertSame(0, EaAssetYear::where('ea_asset_id', $foreign->id)->where('year', 2026)->count());

        $second = $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/assets/carry-over', ['year' => 2026]);
        $second->assertOk();
        $second->assertJsonPath('created', 0);
        $this->assertSame(3, EaAssetYear::where('ea_asset_id', $asset->id)->count());
        $this->assertSame(2, EaAuditLog::where('action', 'carry_over')->count());
    }

    public function test_private_assets_are_carried_over_into_the_private_directory(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Privat', 25);
        EaAssetYear::where('ea_asset_id', $asset->id)->where('year', 2026)->delete();

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/assets/carry-over', ['year' => 2026]);
        $response->assertCreated();
        $response->assertJsonPath('created', 1);

        $row = EaAssetYear::where('ea_asset_id', $asset->id)->where('year', 2026)->firstOrFail();
        $this->assertSame('private', $row->scope);
        $this->assertSame(75, (int) DB::table('ea_asset_years')->where('id', $row->id)->value('private_use_percent'));
    }

    public function test_carry_over_accepts_a_booking_year_without_a_pinned_basis(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Fortgeführt', 100, usefulLife: 5);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/ea/assets/carry-over', ['year' => 2027]);
        $response->assertCreated();
        $response->assertJsonPath('created', 1);

        // 240000 ct / 5 Jahre = 48000 ct je Jahr; 2027 hat keinen Regelplan
        // mehr in `PINNED`, ist aber buchbar (EaYear::accepts()).
        $row = EaAssetYear::forCurrentBrand()->where('ea_asset_id', $asset->id)->where('year', 2027)->firstOrFail();
        $this->assertSame(2026, (int) $row->carry_over_from_year);
        $this->assertSame('planned', $row->status);
        $this->assertSame(48000, (int) $row->depreciation_cents);
        $this->assertSame(48000, (int) $row->book_value_cents);
    }

    public function test_a_year_before_the_first_rule_year_is_rejected(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/ea/assets/carry-over', ['year' => 2023])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }

    private function asset(string $name, int $share, string $brand = 'rp', int $usefulLife = 3): EaAsset
    {
        $asset = EaAsset::create([
            'brand' => $brand,
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2024-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => $usefulLife,
            'business_share' => $share,
            'scope' => $share < 50 ? 'private' : 'business',
            'method' => 'linear',
        ]);

        $annual = intdiv(240000, $usefulLife);

        foreach ([2024, 2025, 2026] as $year) {
            EaAssetYear::create([
                'brand' => $brand,
                'ea_asset_id' => $asset->id,
                'year' => $year,
                'scope' => $asset->scope,
                'method' => 'linear',
                'depreciation_cents' => $annual,
                'book_value_cents' => 240000 - ($year - 2024) * $annual,
                'private_use_percent' => 100 - $share,
                'private_use_cents' => 0,
                'business_use_cents' => $annual,
                'status' => 'planned',
            ]);
        }

        return $asset;
    }
}
