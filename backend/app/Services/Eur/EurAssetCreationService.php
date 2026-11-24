<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\DegressiveRules;
use App\Eur\Reference\ReferenceTables;
use App\Eur\Support\Share;
use App\Models\EurAsset;
use App\Models\EurAssetGroup;
use App\Models\EurAssetYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EAR · Gemeinsamer Anlagegut-Anlagepfad (Portal #57, #59).
 *
 * Der selbe Code erzeugt ein Gut über `/assets` und über den Beleg-Split:
 * AfA-Plan, automatische Entnahme und Eigenbelege entstehen genau wie beim
 * manuellen Anlegen. Eine Gruppe bucht weiterhin EINMAL für alle Mitglieder.
 * Alle Beträge sind ganze Cent.
 */
final class EurAssetCreationService
{
    public function __construct(
        private readonly EurAssetEigenbelegService $eigenbelegService,
        private readonly EurEntnahmeService $entnahmeService,
        private readonly EurAssetGroupService $groupService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validierte Attribute inkl. `brand` und `scope`
     */
    public function create(array $data, ?EurAssetGroup $group, ?string $positionKey = null): EurAsset
    {
        $calculator = new DepreciationCalculator(new RuleValues);
        $month = (int) Carbon::parse($data['purchase_date'])->format('n');
        $data['category'] ??= $this->suggestedCategory((string) $data['name'])['type'];

        if ($data['method'] === DepreciationCalculator::METHOD_DEGRESSIVE) {
            $this->assertDegressiveAllowed($data, $calculator);
        }

        return DB::transaction(function () use ($data, $calculator, $month, $group, $positionKey): EurAsset {
            $asset = EurAsset::create($data);
            $this->writePlan($asset, $calculator, $month);

            // Portal #57: eine Gruppe bucht EINMAL für alle Mitglieder.
            if ($group === null) {
                // EUE-055: die Entnahme bei Anschaffung entsteht ohne Benutzeraktion.
                $this->entnahmeService->createForAsset($asset, $positionKey);
            } else {
                $this->groupService->sync($group->refresh());
            }

            return $asset;
        });
    }

    public function writePlan(EurAsset $asset, DepreciationCalculator $calculator, int $month, ?int $switchYear = null): void
    {
        $plan = $asset->method === DepreciationCalculator::METHOD_DEGRESSIVE
            ? $calculator->degressivePlan((int) $asset->cost_cents, (int) $asset->useful_life_years, (int) $asset->degressive_rate, $month, $switchYear)
            : $calculator->linearPlan((int) $asset->cost_cents, (int) $asset->useful_life_years, $month);

        $purchaseYear = (int) $asset->purchase_date->format('Y');
        // D-89: der Gruppenanteil gilt für alle Berechnungen; der eigene
        // Anteil des Guts bleibt unverändert als Information erhalten.
        $privatePercent = 100 - $asset->effectiveBusinessShare();

        foreach ($plan as $row) {
            $privateCents = Share::apply($row['depreciation_cents'], $privatePercent);

            $yearRow = EurAssetYear::create([
                'brand' => $asset->brand,
                'eur_asset_id' => $asset->id,
                'year' => $purchaseYear + $row['year'] - 1,
                'scope' => $asset->scope,
                'method' => $row['method'],
                'depreciation_cents' => $row['depreciation_cents'],
                'book_value_cents' => $row['book_value_cents'],
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $row['depreciation_cents'] - $privateCents,
                'status' => 'planned',
            ]);

            // D-85/EUE-055: im privaten Verzeichnis entsteht der Eigenbeleg
            // automatisch mit dem Plan — ohne Benutzeraktion und ohne Buchung.
            if ($asset->scope === 'private' && $asset->eur_asset_group_id === null) {
                $this->eigenbelegService->createForYear($asset, $yearRow);
            }
        }
    }

    /**
     * D-89: nach einer Änderung der Abrechnungseinheit (Gruppe wechseln,
     * Mitglied aufteilen, Gruppenanteil ändern) übernehmen die noch offenen
     * Planzeilen den effektiven Anteil. Gebuchte Jahre sind final und werden
     * nie umgeschrieben — eine solche Änderung ist ohnehin nur für Gruppen
     * ohne gebuchtes Jahr zulässig.
     *
     * @return int Anzahl der aktualisierten Planzeilen
     */
    public function recalculateUnbookedPlanShares(EurAsset $asset): int
    {
        $privatePercent = 100 - $asset->effectiveBusinessShare();
        $updated = 0;

        $unbooked = $asset->years()
            ->whereNull('eur_entry_id')
            ->where('status', '!=', 'booked')
            ->get();

        foreach ($unbooked as $year) {
            $depreciationCents = (int) $year->depreciation_cents;
            $privateCents = Share::apply($depreciationCents, $privatePercent);

            $year->forceFill([
                'private_use_percent' => $privatePercent,
                'private_use_cents' => $privateCents,
                'business_use_cents' => $depreciationCents - $privateCents,
            ])->save();

            $updated++;
        }

        return $updated;
    }

    /**
     * Kategorievorschlag aus dem gepinnten Referenzkatalog — nie hartcodiert.
     *
     * @return array{type: string, e1a: string|null, share_percent: int|null}
     */
    public function suggestedCategory(string $name): array
    {
        $categories = array_values(array_filter(
            ReferenceTables::load()->expenseKinds(),
            static fn (array $kind): bool => str_contains((string) ($kind['e1a'] ?? ''), '9130'),
        ));

        if ($categories === []) {
            return ['type' => 'Anlagegüter', 'e1a' => '9130', 'share_percent' => null];
        }

        $keywords = ['laptop' => 'Laptop', 'drucker' => 'Drucker', 'website' => 'Firmenwebsite', 'geringwertig' => 'Geringwertig'];
        $normalized = mb_strtolower($name);

        foreach ($keywords as $needle => $type) {
            if (str_contains($normalized, $needle)) {
                foreach ($categories as $category) {
                    if (str_contains(mb_strtolower((string) $category['type']), mb_strtolower($type))) {
                        return $category;
                    }
                }
            }
        }

        foreach ($categories as $category) {
            if (str_contains(mb_strtolower((string) $category['type']), 'andere anlagegüter')) {
                return $category;
            }
        }

        return $categories[0];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDegressiveAllowed(array $data, DepreciationCalculator $calculator): void
    {
        if (empty($data['degressive_rate']) || ! $calculator->isDegressiveRateValid((int) $data['degressive_rate'])) {
            abort(422, 'Für die degressive AfA ist ein Satz zwischen 1 % und 30 % erforderlich.');
        }

        if (! $calculator->isBusinessShareValid((int) $data['business_share'])) {
            abort(422, 'Der Betriebsanteil muss zwischen 50 % und 100 % in 5-%-Schritten liegen.');
        }

        // DEG-006/007/008: die Ausschlüsse kommen aus den gepinnten Regeln;
        // die zulässigen immateriellen Ausnahmen aus dem DEG-Artefakt.
        if ($this->flag($data, 'is_used')) {
            abort(422, 'Degressive AfA ist für gebrauchte Wirtschaftsgüter nicht zulässig.');
        }

        if ($this->flag($data, 'is_immaterial')
            && ! (new DegressiveRules)->isAllowedImmaterialException($data['immaterial_exception'] ?? null)) {
            abort(422, 'Degressive AfA ist für immaterielle Wirtschaftsgüter nur mit einer dokumentierten Ausnahme zulässig.');
        }

        if ($this->flag($data, 'special_depreciation') && ! $this->flag($data, 'is_electric_vehicle')) {
            abort(422, 'Degressive AfA ist bei gesetzlicher Sonder-AfA nicht zulässig.');
        }
    }

    /**
     * HTML-Formulare liefern "1"/"0", JSON liefert echte Booleans.
     *
     * @param  array<string, mixed>  $data
     */
    private function flag(array $data, string $key): bool
    {
        return filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
