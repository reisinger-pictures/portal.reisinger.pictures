<?php

declare(strict_types=1);

namespace App\Services\Ea;

use App\Ea\Parity\Money;
use App\Ea\Reference\EaYear;
use App\Ea\Support\EaAssetPositionKey;
use App\Ea\Support\Share;
use App\Models\EaAsset;
use App\Models\EaAssetGroup;
use App\Models\EaAssetGroupYear;
use App\Models\EaEntry;
use Carbon\Carbon;

/**
 * EAR · Abrechnung einer Anlagegruppe als EINER Einheit (Portal #57).
 *
 * Der Betriebsanteil liegt am Gruppenstamm und überschreibt den Anteil der
 * Mitglieder. Betriebliche Gruppen erzeugen genau EINE Entnahme-Einnahme
 * (KZ 9040, EUE-055) über die Summe der Anschaffungskosten; private Gruppen
 * erzeugen genau EINEN Eigenbeleg je Jahr über die summierten linearen AfA-
 * Beträge, kumuliert begrenzt auf die Summe der Anschaffungskosten.
 *
 * Belege bleiben an den einzelnen Gütern. Alle Beträge sind ganze Cent.
 */
final class EaAssetGroupService
{
    public const RECEIPT_PREFIX = 'GR-';

    /** D-89: eine Gruppe mit gebuchtem Jahr ist gegen Änderungen gesperrt. */
    public const BOOKED_GROUP_MESSAGE = 'Eine Anlagegruppe mit gebuchtem Jahr darf nicht geändert werden.';

    public function __construct(
        private readonly EaEntnahmeService $entnahmeService = new EaEntnahmeService,
        private readonly EaAssetEigenbelegService $eigenbelegService = new EaAssetEigenbelegService,
    ) {}

    /**
     * @param  int|null  $horizon  Zieljahr eines Jahreswechsels; die Gruppe erhält
     *                             Jahreszeilen mindestens bis
     *                             `max(LAST_PINNED, laufendes Buchungsjahr,
     *                             jüngste vorhandene Zeile)` und nach oben
     *                             erweitert durch dieses Zieljahr.
     */
    public function sync(EaAssetGroup $group, ?int $horizon = null): void
    {
        $members = $group->assets()->get()->all();

        // Eine geleerte Gruppe ist keine Abrechnungseinheit mehr: sonst
        // verbliebe nach einem Gruppenwechsel eine 0-Cent-Entnahme der alten
        // Gruppe (Regressionsfall Portal #59).
        if ($members === []) {
            EaEntry::forCurrentBrand()
                ->where('receipt_no', self::RECEIPT_PREFIX.$group->id)
                ->where('kind_type', 'Entnahme')
                ->delete();
            $group->years()->delete();

            return;
        }

        $totalCost = array_sum(array_map(static fn (EaAsset $a): int => (int) $a->cost_cents, $members));
        $businessPercent = (int) $group->business_share;
        $privatePercent = 100 - $businessPercent;

        $this->syncEntnahme($group, $totalCost, $businessPercent, $privatePercent);
        $this->syncEigenbelege($group, $members, $totalCost, $businessPercent, $horizon);
    }

    /**
     * Ein grouped asset wird aus der Einzelabrechnung genommen: eine bereits
     * automatisch erzeugte Entnahme und einzelne Eigenbelege werden entfernt.
     * Gebuchte Jahre machen eine Zuordnung unzulässig (fail closed).
     */
    public function assertAssignable(EaAsset $asset): void
    {
        if ($asset->years()->where('status', 'booked')->exists()) {
            throw new \InvalidArgumentException(
                'Anlagegut mit gebuchtem Jahr kann keiner Gruppe zugeordnet werden.'
            );
        }
    }

    /**
     * D-89 (fail closed): eine Änderung des Gruppenanteils oder der
     * Mitgliedschaft ist unzulässig, sobald ein aktuelles Mitglied ein
     * gebuchtes Jahr hat. `assertAssignable` schützt nur die Zuordnung
     * einzelner Güter; dieser Guard schützt die Gruppe selbst.
     */
    public function hasBookedYear(EaAssetGroup $group): bool
    {
        return $group->assets()
            ->whereHas('years', static fn ($query) => $query->where('status', 'booked'))
            ->exists();
    }

    public function assertGroupMutable(EaAssetGroup $group): void
    {
        if ($this->hasBookedYear($group)) {
            throw new \InvalidArgumentException(self::BOOKED_GROUP_MESSAGE);
        }
    }

    public function detach(EaAsset $asset): void
    {
        $this->removeIndividualBookkeeping($asset);
    }

    public function removeIndividualBookkeeping(EaAsset $asset): void
    {
        $query = EaEntry::forCurrentBrand()
            ->where('receipt_no', $asset->invoice_no)
            ->where('kind_type', 'Entnahme');

        // Portal #59: Split-Geschwister teilen dieselbe Belegnummer. Ohne
        // Positionsschlüssel würde das Gruppieren eines Guts die Entnahmen
        // seiner ungruppierten Geschwister mitlöschen.
        $positionKey = EaAssetPositionKey::forAsset($asset);
        if ($positionKey !== null) {
            $query->where('description', 'like', '% (Position '.$positionKey.')');
        }

        $query->delete();

        $asset->years()->update([
            'eigenbeleg_no' => null,
            'theoretical_depreciation_cents' => null,
            'usage_percent' => null,
            'eigenbeleg_cents' => null,
            'eigenbeleg_created_at' => null,
        ]);
    }

    /**
     * Nach dem Trennen gilt wieder die Einzelabrechnung (D-89, Portal #59).
     * Die Dienste sind idempotent: es entsteht keine zweite Entnahme und kein
     * zweiter Eigenbeleg für dasselbe Gut.
     */
    public function restoreIndividualBookkeeping(EaAsset $asset): void
    {
        $asset = $asset->refresh();

        // Portal #59: mehrere Positionen teilen eine Belegnummer; der
        // Positionsschlüssel aus der Pivot hält ihre Entnahmen getrennt.
        $this->entnahmeService->createForAsset($asset, EaAssetPositionKey::forAsset($asset));

        if ($asset->scope === 'private') {
            foreach ($asset->years as $year) {
                $this->eigenbelegService->createForYear($asset, $year);
            }
        }
    }

    private function syncEntnahme(EaAssetGroup $group, int $totalCost, int $businessPercent, int $privatePercent): void
    {
        $receiptNo = self::RECEIPT_PREFIX.$group->id;
        $query = EaEntry::forCurrentBrand()
            ->where('receipt_no', $receiptNo)
            ->where('kind_type', 'Entnahme');

        // Nur betriebliche Gruppen buchen die EUE-055-Entnahme. Private
        // Gruppen (< 50 % betrieblich) dokumentieren mit dem Eigenbeleg.
        if ($businessPercent < 50 || $privatePercent <= 0) {
            $query->delete();

            return;
        }

        $cents = Share::apply($totalCost, $privatePercent);
        $existing = $query->first();

        if ($existing !== null) {
            $existing->update(['amount_cents' => $cents, 'business_amount_cents' => $cents]);

            return;
        }

        $date = Carbon::parse($group->created_at ?? now());
        EaEntry::create([
            'brand' => $group->brand,
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'direction' => 'income',
            'kind_type' => 'Entnahme',
            'category' => 'Entnahme',
            'description' => 'Entnahme Anlagegruppe: '.$group->name,
            'e1a_code' => '9040',
            'business_share' => 100,
            'amount_cents' => $cents,
            'business_amount_cents' => $cents,
            'receipt_no' => $receiptNo,
            'note' => sprintf('Automatische Entnahme Anlagegruppe #57 (%d %% privat)', $privatePercent),
        ]);
    }

    /**
     * @param  list<EaAsset>  $members
     */
    private function syncEigenbelege(
        EaAssetGroup $group,
        array $members,
        int $totalCost,
        int $businessPercent,
        ?int $horizon = null,
    ): void {
        if ($businessPercent >= 50 || $members === []) {
            $group->years()->delete();

            return;
        }

        $annual = 0;
        foreach ($members as $member) {
            $life = (int) $member->useful_life_years;
            if ($life > 0) {
                $annual += Money::divideCents((int) $member->cost_cents, $life);
            }
        }

        if ($annual <= 0) {
            return;
        }

        $firstYear = min(array_map(static fn (EaAsset $a): int => (int) $a->purchase_date->format('Y'), $members));
        // Der Eigenbeleg-Träger reicht mindestens bis zum laufenden
        // Buchungsjahr (und bis zum letzten Regeljahr), wird durch das Zieljahr
        // eines späteren Jahreswechsels nach oben erweitert und kürzt nie eine
        // bereits vorhandene Zeile: `sync()` läuft auch ohne Zieljahr (z. B. aus
        // dem Gruppen-Update) und würde sonst einen früheren Jahreswechsel
        // zurückdrehen. Deshalb gehört das Bestands-Maximum in den Horizont —
        // die vorhandenen Zeilen werden im Lauf neu berechnet und über
        // `$keptIds` gehalten. Die kumulierte Anschaffungswert-Grenze
        // (`$cumulative + $eigenbeleg > $totalCost`) bleibt unverändert; sie
        // begrenzt weiterhin nur die Summe der Eigenbelege.
        $lastYear = max(
            $horizon ?? 0,
            EaYear::LAST_PINNED,
            EaYear::currentOrLast(),
            (int) $group->years()->max('year'),
        );
        $cumulative = 0;
        $book = 1;
        $keptIds = [];

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $eigenbeleg = Share::apply($annual, $businessPercent);

            if ($cumulative + $eigenbeleg > $totalCost) {
                break;
            }

            $row = EaAssetGroupYear::firstOrCreate(
                ['ea_asset_group_id' => $group->id, 'year' => $year],
                [
                    'brand' => $group->brand,
                    'depreciation_cents' => $annual,
                    'book_value_cents' => $book,
                ],
            );

            // Die Beträge werden bei jeder Änderung der Mitgliedschaft oder
            // des Anteils neu berechnet; Belegnummer und Erstellungszeitpunkt
            // bleiben stabil.
            $row->forceFill([
                'depreciation_cents' => $annual,
                'book_value_cents' => $book,
                'eigenbeleg_no' => sprintf('EBG-%d-%d', $group->id, $year),
                'theoretical_depreciation_cents' => $annual,
                'usage_percent' => $businessPercent,
                'eigenbeleg_cents' => $eigenbeleg,
                'eigenbeleg_created_at' => $row->eigenbeleg_created_at ?? now(),
            ])->save();

            $keptIds[] = $row->id;
            $cumulative += $eigenbeleg;
        }

        // Überzählige oder inzwischen über die Obergrenze fallende Zeilen
        // entfernen, damit die Gruppe genau eine Zeile je Jahr behält.
        $group->years()->whereNotIn('id', $keptIds)->delete();
    }
}
