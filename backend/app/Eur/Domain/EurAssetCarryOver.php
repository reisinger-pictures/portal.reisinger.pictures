<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
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
        if ($row->wasRecentlyCreated
            && ($row->scope ?? $asset->scope) === 'private'
            && $asset->eur_asset_group_id === null) {
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

        if ($row === null && $this->isPrivate($asset)) {
            return $this->privateContinuationRow($asset, $targetYear);
        }

        return $row === null ? null : [
            'method' => $row['method'],
            'depreciation_cents' => (int) $row['depreciation_cents'],
            'book_value_cents' => (int) $row['book_value_cents'],
        ];
    }

    /**
     * EUE-055: das private Verzeichnis schreibt linear über die Nutzungsdauer
     * hinaus fort, solange das Gut betrieblich genutzt wird (der Carry-over
     * überspringt veräußerte Güter). Die jährliche fiktive AfA bleibt
     * `(1 / Nutzungsdauer) × Betriebsanteil × Anschaffungswert` in ganzen Cent;
     * die kumulierten Eigenbelege dürfen den initialen Anschaffungswert nicht
     * überschreiten. Ein Jahr, das die Grenze überschreiten würde, wird nicht
     * angelegt — es gibt bewusst keine gekürzte Restbuchung.
     *
     * @return array{method: string, depreciation_cents: int, book_value_cents: int}|null
     */
    private function privateContinuationRow(EurAsset $asset, int $targetYear): ?array
    {
        $purchaseYear = (int) $asset->purchase_date->format('Y');

        if ($targetYear <= $purchaseYear) {
            return null;
        }

        $cost = (int) $asset->cost_cents;
        $life = (int) $asset->useful_life_years;
        $businessPercent = (int) $asset->business_share;

        if ($cost <= 0 || $life <= 0 || $businessPercent <= 0) {
            return null;
        }

        $theoretical = Money::divideCents($cost, $life);
        $annualEigenbeleg = Share::apply($theoretical, $businessPercent);

        if ($annualEigenbeleg <= 0) {
            return null;
        }

        $priorYears = $asset->years()->where('year', '<', $targetYear);
        $cumulative = (int) (clone $priorYears)->sum('eigenbeleg_cents');

        if ($cumulative + $annualEigenbeleg > $cost) {
            return null;
        }

        // Der Plan ist aufgebraucht; der Restbuchwert bleibt gemäß EUE-050
        // auf dem Mindestwert und steigt nicht wieder an.
        // years() sortiert aufsteigend; orderByDesc() würde nur anhängen und
        // value() das früheste Jahr liefern — daher zuerst reorder().
        $lastBookValue = (int) ((clone $priorYears)->reorder()->orderByDesc('year')->value('book_value_cents') ?? $cost);

        return [
            'method' => DepreciationCalculator::METHOD_LINEAR,
            'depreciation_cents' => $theoretical,
            'book_value_cents' => $this->calculator->residualCents($lastBookValue, $theoretical),
        ];
    }

    private function isPrivate(EurAsset $asset): bool
    {
        $scope = $asset->scope ?? ((int) $asset->business_share < 50 ? 'private' : 'business');

        return $scope === 'private';
    }
}
