<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\RuleValues;
use App\Eur\Support\Share;
use App\Models\EurAsset;
use App\Models\EurAssetYear;
use App\Services\Eur\EurAssetEigenbelegService;

/**
 * EAR · idempotenter Jahreswechsel der Anlagenverzeichnisse (D-85).
 *
 * Für jedes aktive Anlagegut der Marke, das im Zieljahr noch keine Zeile hat,
 * wird genau die Zeile des bereits berechneten AfA-Plans angelegt, die
 * `writePlan()` auch erzeugt hätte. Vorhandene Zeilen — insbesondere gebuchte
 * Jahre — werden nie verändert; veräußerte Güter werden übersprungen.
 * Alle Beträge bleiben ganze Cent.
 */
final class EurAssetCarryOver
{
    public function __construct(
        private readonly DepreciationCalculator $calculator = new DepreciationCalculator(new RuleValues),
        private readonly EurAssetEigenbelegService $eigenbelegService = new EurAssetEigenbelegService,
    ) {}

    /**
     * @return int Anzahl der neu angelegten Jahreszeilen
     */
    public function carryOver(int $targetYear, string $brand): int
    {
        $created = 0;

        EurAsset::where('brand', $brand)
            ->where('status', 'active')
            ->whereNull('disposed_on')
            ->orderBy('id')
            ->chunkById(500, function ($assets) use ($targetYear, $brand, &$created): void {
                foreach ($assets as $asset) {
                    if ($this->carryOverAsset($asset, $targetYear, $brand)) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    private function carryOverAsset(EurAsset $asset, int $targetYear, string $brand): bool
    {
        $planRow = $this->planRow($asset, $targetYear);

        if ($planRow === null) {
            return false;
        }

        $privatePercent = 100 - (int) $asset->business_share;
        $depreciationCents = (int) $planRow['depreciation_cents'];
        $privateCents = Share::apply($depreciationCents, $privatePercent);

        $row = EurAssetYear::firstOrCreate(
            ['eur_asset_id' => $asset->id, 'year' => $targetYear],
            [
                'brand' => $brand,
                'scope' => $asset->scope ?? ((int) $asset->business_share < 50 ? 'private' : 'business'),
                'carry_over_from_year' => $targetYear - 1,
                'method' => $planRow['method'],
                'depreciation_cents' => $depreciationCents,
                'book_value_cents' => (int) $planRow['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $depreciationCents - $privateCents,
                'status' => 'planned',
            ],
        );

        // D-85/EUE-055: der Eigenbeleg des privaten Verzeichnisses entsteht
        // automatisch mit der neuen Jahreszeile, ohne Benutzeraktion.
        if ($row->wasRecentlyCreated && ($row->scope ?? $asset->scope) === 'private') {
            $this->eigenbelegService->createForYear($asset, $row);
        }

        return $row->wasRecentlyCreated;
    }

    /**
     * @return array{method: string, depreciation_cents: int, book_value_cents: int}|null
     */
    private function planRow(EurAsset $asset, int $targetYear): ?array
    {
        $purchaseYear = (int) $asset->purchase_date->format('Y');
        $month = (int) $asset->purchase_date->format('n');
        $cost = (int) $asset->cost_cents;
        $life = (int) $asset->useful_life_years;

        // Ein vollzogener Wechsel liegt nicht mehr als vollständiger Plan vor;
        // hier ist der letzte gespeicherte Restbuchwert die einzige gültige
        // Basis. Es wird nichts aus gebuchten Jahren umgeschrieben.
        if ($asset->switched_to_linear_at !== null) {
            $last = $asset->years()->where('year', '<', $targetYear)->orderByDesc('year')->first();

            if ($last === null) {
                return null;
            }

            $remaining = $this->calculator->linearPlan((int) $last->book_value_cents, $life - ($targetYear - $purchaseYear), 1);
            $row = $remaining[0] ?? null;

            return $row === null ? null : [
                'method' => $row['method'],
                'depreciation_cents' => (int) $row['depreciation_cents'],
                'book_value_cents' => (int) $row['book_value_cents'],
            ];
        }

        $plan = $asset->method === DepreciationCalculator::METHOD_DEGRESSIVE
            ? $this->calculator->degressivePlan($cost, $life, (int) $asset->degressive_rate, $month)
            : $this->calculator->linearPlan($cost, $life, $month);

        $row = $plan[$targetYear - $purchaseYear] ?? null;

        return $row === null ? null : [
            'method' => $row['method'],
            'depreciation_cents' => (int) $row['depreciation_cents'],
            'book_value_cents' => (int) $row['book_value_cents'],
        ];
    }
}
