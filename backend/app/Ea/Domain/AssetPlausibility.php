<?php

declare(strict_types=1);

namespace App\Ea\Domain;

use InvalidArgumentException;

/**
 * EAR · Plausibilitätsprüfung der Vorjahreswerte (EUE-056, EUE-057).
 *
 * Die erwarteten Werte kommen ausschließlich aus dem gepinnten
 * Abschreibungsplan der `DepreciationCalculator` — bei degressiver AfA aus
 * dem gewählten Plan (DEG-015), sonst aus dem linearen Plan (EUE-049). Es gibt
 * keine zweite Formel und keine Nachrechnung mit Float-Geldwerten.
 *
 * EUE-056 vergleicht die Vorjahres-AfA mit der Toleranz aus dem gepinnten
 * Regelkatalog (`EUE-056 / Toleranz AfA`, 0,01 € = 1 Cent). EUE-057 prüft auf
 * überzählige Vorjahreswerte, fehlende Restbuchwerte und eine Abweichung des
 * Restbuchwerts. Die ODS rundet bei EUE-057 auf ganze Euro; das Portal tut
 * dasselbe über ganzzahlige Cent-Arithmetik (`intdiv(wert + 50, 100)`).
 *
 * Nach einem vollzogenen Methodenwechsel ist der gespeicherte Plan die einzige
 * gültige Basis (DEG-009/014): gebuchte Jahre werden nie umgeschrieben und
 * `switched_to_linear_at` ist der Zeitpunkt der Entscheidung, nicht das
 * Wechseljahr des Plans. Wie `EaAssetCarryOver` werden für solche Anlagen
 * keine Plausibilitätsvergleiche gegen einen rekonstruierten Plan gezogen —
 * ein solcher Vergleich würde degressive Historie gegen einen linearen Plan
 * melden und Fehlalarme erzeugen.
 *
 * Die Hinweise sind Antwortfelder, keine 422-Fehler: die ODS zeigt ein ⚠ an,
 * blockiert die Eingabe aber nicht.
 */
final class AssetPlausibility
{
    public function __construct(
        private readonly DepreciationCalculator $calculator,
        private readonly int $toleranceCents,
    ) {
        if ($toleranceCents < 0) {
            throw new InvalidArgumentException('Die AfA-Toleranz darf nicht negativ sein.');
        }
    }

    /**
     * @param  array{
     *     current_year: int,
     *     acquisition_year: int,
     *     acquisition_month: int,
     *     cost_cents: int,
     *     useful_life_years: int,
     *     method: string,
     *     switched: bool,
     *     degressive_rate_per_10000: int,
     *     switch_year: int|null,
     *     years: list<array{year: int, depreciation_cents: int, book_value_cents: int|null}>
     * }  $asset
     * @return list<array{code: string, text: string}>
     */
    public function warnings(array $asset): array
    {
        $currentYear = $asset['current_year'] ?? null;
        $acquisitionYear = $asset['acquisition_year'] ?? null;

        if (! is_int($currentYear) || ! is_int($acquisitionYear)) {
            throw new InvalidArgumentException('Plausibilität braucht ganzzahlige Jahre.');
        }

        // DEG-009/014: nach einem Wechsel ist der gespeicherte Plan maßgeblich.
        if (($asset['switched'] ?? false) === true) {
            return [];
        }

        $expected = $this->expectedPlan($asset);
        $warnings = [];
        $priorRows = [];

        foreach ($asset['years'] ?? [] as $row) {
            $year = $row['year'] ?? null;

            if (! is_int($year) || $year >= $currentYear) {
                continue;
            }

            $priorRows[$year] = $row;
            $index = $year - $acquisitionYear;
            $expectedRow = $expected[$index] ?? null;

            if ($expectedRow === null) {
                continue;
            }

            $delta = (int) $row['depreciation_cents'] - (int) $expectedRow['depreciation_cents'];

            if (abs($delta) <= $this->toleranceCents) {
                continue;
            }

            $warnings[] = [
                'code' => 'EUE-056',
                'text' => sprintf(
                    '⚠ Abschreibung %d passt nicht zum erwarteten Plan (erwartet: %s, tatsächlich: %s).',
                    $year,
                    self::euro((int) $expectedRow['depreciation_cents']),
                    self::euro((int) $row['depreciation_cents']),
                ),
            ];
        }

        $priorYear = $currentYear - 1;
        $stored = $priorRows[$priorYear] ?? null;
        $expectedRow = $expected[$priorYear - $acquisitionYear] ?? null;

        // EUE-057: im Anschaffungsjahr darf keine Vorjahres-AfA stehen.
        if ($acquisitionYear === $currentYear) {
            foreach ($priorRows as $row) {
                if ((int) $row['depreciation_cents'] > 0) {
                    $warnings[] = [
                        'code' => 'EUE-057',
                        'text' => sprintf('⚠ Abschreibung %d entfernen.', $priorYear),
                    ];
                    break;
                }
            }

            return $warnings;
        }

        if ($acquisitionYear > $currentYear) {
            return $warnings;
        }

        // EUE-050 erlaubt keinen Restbuchwert unter 1 Cent; 0 ist im Portal
        // das Äquivalent zur leeren ODS-Zelle Q3.
        if ($stored === null || $stored['book_value_cents'] === null || (int) $stored['book_value_cents'] <= 0) {
            $warnings[] = [
                'code' => 'EUE-057',
                'text' => sprintf('⚠ Restbuchwert %d fehlt.', $priorYear),
            ];

            return $warnings;
        }

        if ($expectedRow === null) {
            return $warnings;
        }

        $expectedWholeEuro = self::wholeEuro((int) $expectedRow['book_value_cents']);
        $storedWholeEuro = self::wholeEuro((int) $stored['book_value_cents']);

        if ($expectedWholeEuro !== $storedWholeEuro) {
            $warnings[] = [
                'code' => 'EUE-057',
                'text' => sprintf(
                    '⚠ Restbuchwert %d stimmt nicht (Differenz: %d ct).',
                    $priorYear,
                    abs((int) $stored['book_value_cents'] - (int) $expectedRow['book_value_cents']),
                ),
            ];
        }

        return $warnings;
    }

    /**
     * @param  array{cost_cents: int, useful_life_years: int, acquisition_month: int, method: string, degressive_rate_per_10000: int, switch_year: int|null}  $asset
     * @return list<array{year: int, method: string, depreciation_cents: int, book_value_cents: int}>
     */
    private function expectedPlan(array $asset): array
    {
        $cost = (int) $asset['cost_cents'];
        $life = (int) $asset['useful_life_years'];
        $month = (int) $asset['acquisition_month'];
        $rate = (int) $asset['degressive_rate_per_10000'];
        $switchYear = $asset['switch_year'] ?? null;

        if ($asset['method'] === DepreciationCalculator::METHOD_DEGRESSIVE && $rate > 0) {
            return $this->calculator->degressivePlan($cost, $life, $rate, $month, $switchYear);
        }

        return $this->calculator->linearPlan($cost, $life, $month);
    }

    private static function wholeEuro(int $cents): int
    {
        return intdiv($cents + 50, 100);
    }

    private static function euro(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf(
            '%s%d,%02d €',
            $sign,
            intdiv($absolute, 100),
            $absolute % 100,
        );
    }
}
