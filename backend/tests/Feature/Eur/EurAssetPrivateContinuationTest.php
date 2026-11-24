<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Eur\Domain\EurAssetCarryOver;
use App\Eur\Support\Share;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Support\BrandRegistry;

/**
 * EUE-055: die private Einlage wird linear über die Nutzungsdauer hinaus
 * fortgeschrieben; die kumulierten Eigenbelege sind auf den Anschaffungswert
 * begrenzt. Betriebliche Güter behalten den festen Plan.
 */
class EurAssetPrivateContinuationTest extends EurBookkeepingTestCase
{
    public function test_private_asset_continues_beyond_useful_life_until_initial_cost_is_reached(): void
    {
        $asset = $this->asset('Privat-Fortlauf', 30, 2020, 2);
        $this->year($asset, 2020, 50000, 15000);
        $this->year($asset, 2021, 50000, 15000);
        // Kumuliert: 30000 ct. 100000 ct / 15000 ct je Jahr => vier weitere
        // ganze Jahre (45000, 60000, 75000, 90000), das fünfte würde 105000
        // ct ergeben und darf nicht angelegt werden.
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $this->assertSame(1, (new EurAssetCarryOver)->carryOver($year, BrandRegistry::currentId()));
        }
        $this->assertSame(0, (new EurAssetCarryOver)->carryOver(2026, BrandRegistry::currentId()));

        $rows = $asset->years()->orderBy('year')->get();
        $this->assertSame([2020, 2021, 2022, 2023, 2024, 2025], $rows->pluck('year')->all());
        $this->assertSame(90000, (int) $rows->sum('eigenbeleg_cents'));

        foreach ($rows->whereIn('year', [2022, 2023, 2024, 2025]) as $row) {
            $this->assertSame(50000, (int) $row->depreciation_cents);
            $this->assertSame(15000, (int) $row->eigenbeleg_cents);
            $this->assertSame(30, (int) $row->usage_percent);
            $this->assertSame(15000, (int) $row->business_use_cents);
            $this->assertSame(1, (int) $row->book_value_cents);
            $this->assertSame('private', $row->scope);
            $this->assertSame('linear', $row->method);
            $this->assertStringStartsWith('EB-', (string) $row->eigenbeleg_no);
        }
    }

    public function test_business_asset_stops_at_the_end_of_its_useful_life(): void
    {
        $asset = $this->asset('Betrieb-Fest', 100, 2024, 2);
        $this->year($asset, 2024, 50000, null);
        $this->year($asset, 2025, 50000, null);

        // Der Zielaufruf liegt außerhalb des API-Fensters, die Domäne selbst
        // darf ihn prüfen: betriebliche Güter bekommen keine Fortführung.
        $this->assertSame(0, (new EurAssetCarryOver)->carryOver(2026, BrandRegistry::currentId()));
        $this->assertSame(2, $asset->years()->count());
    }

    public function test_continuation_keeps_the_residual_book_value_for_a_long_life_asset(): void
    {
        // Regression: years() sortiert aufsteigend. Ohne reorder() lieferte
        // orderByDesc()->value() das früheste Jahr (80000 ct) und der
        // Restbuchwert spränge auf 60000 ct statt bei 1 ct zu bleiben.
        $asset = $this->asset('Langzeit', 30, 2020, 5);
        foreach ([80000, 60000, 40000, 20000, 1] as $index => $book) {
            $year = 2020 + $index;
            $this->year($asset, $year, $index === 4 ? 19999 : 20000, 6000);
            $asset->years()->where('year', $year)->update(['book_value_cents' => $book]);
        }

        $this->assertSame(1, (new EurAssetCarryOver)->carryOver(2025, BrandRegistry::currentId()));
        $row = $asset->years()->where('year', 2025)->firstOrFail();
        $this->assertSame(1, (int) $row->book_value_cents);
        $this->assertSame(6000, (int) $row->eigenbeleg_cents);
    }

    public function test_a_disposed_private_asset_is_not_continued(): void
    {
        $asset = $this->asset('Privat-Veräußert', 30, 2020, 2);
        $this->year($asset, 2020, 50000, 15000);
        $this->year($asset, 2021, 50000, 15000);
        $asset->forceFill(['status' => 'disposed', 'disposed_on' => '2022-06-01'])->save();

        $this->assertSame(0, (new EurAssetCarryOver)->carryOver(2022, BrandRegistry::currentId()));
        $this->assertSame(2, $asset->years()->count());
    }

    public function test_continuation_uses_the_business_share_even_for_a_very_small_share(): void
    {
        // Zieljahr liegt außerhalb des 2-Jahres-Plans: nur hier wird die neue
        // Fortführungslogik überhaupt erreicht (Planindex 2026-2024 = 2 > 2).
        $asset = $this->asset('Kleinanteil', 5, 2024, 2);
        $this->year($asset, 2024, 50000, 2500);
        $this->year($asset, 2025, 50000, 2500);

        $this->assertSame(1, (new EurAssetCarryOver)->carryOver(2026, BrandRegistry::currentId()));
        $row = $asset->years()->where('year', 2026)->firstOrFail();
        $this->assertSame(Share::apply(50000, 5), (int) $row->eigenbeleg_cents);
        $this->assertSame(5, (int) $row->usage_percent);
        $this->assertSame(1, (int) $row->book_value_cents);
    }

    private function asset(string $name, int $businessShare, int $purchaseYear, int $life): EurAsset
    {
        return EurAsset::create([
            'brand' => BrandRegistry::currentId(),
            'name' => $name,
            'invoice_no' => 'PF-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => $purchaseYear.'-01-15',
            'cost_cents' => 100000,
            'useful_life_years' => $life,
            'business_share' => $businessShare,
            'scope' => $businessShare < 50 ? 'private' : 'business',
            'method' => 'linear',
        ]);
    }

    private function year(EurAsset $asset, int $year, int $depreciation, ?int $eigenbeleg): EurAssetYear
    {
        $privatePercent = 100 - (int) $asset->business_share;
        $privateCents = Share::apply($depreciation, $privatePercent);

        $row = EurAssetYear::create([
            'brand' => $asset->brand,
            'eur_asset_id' => $asset->id,
            'year' => $year,
            'scope' => $asset->scope,
            'method' => 'linear',
            'depreciation_cents' => $depreciation,
            'book_value_cents' => 100000 - ($depreciation * ($year - (int) $asset->purchase_date->format('Y') + 1)),
            'private_use_percent' => $privatePercent,
            'private_use_cents' => $privateCents,
            'business_use_cents' => $depreciation - $privateCents,
            'status' => 'planned',
        ]);

        if ($eigenbeleg !== null) {
            $row->forceFill([
                'eigenbeleg_no' => sprintf('EB-%d-%d', $asset->id, $year),
                'theoretical_depreciation_cents' => $depreciation,
                'usage_percent' => (int) $asset->business_share,
                'eigenbeleg_cents' => $eigenbeleg,
                'eigenbeleg_created_at' => now(),
            ])->save();
        }

        return $row;
    }
}
