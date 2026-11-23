<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Parity\RuleValues;
use InvalidArgumentException;

/**
 * EAR · Anlagen und AfA (EUE-049 … EUE-061) sowie degressive AfA
 * (DEG-001 … DEG-015).
 *
 * Alle Beträge sind ganze Cent. Der lineare Plan folgt EUE-049 (Kosten geteilt
 * durch Nutzungsjahre, halb bei Anschaffung Juli–Dezember), der Restbuchwert
 * behält den Erinnerungswert von 0,01 € (EUE-050). Die degressive Methode ist
 * eine Produkterweiterung; ihre Sätze und Grenzen kommen aus dem gepinnten
 * DEG-Artefakt, nicht aus Formeln des Quellprojekts.
 */
final class DepreciationCalculator
{
    public const METHOD_LINEAR = 'linear';

    public const METHOD_DEGRESSIVE = 'degressive';

    public function __construct(private readonly RuleValues $rules) {}

    public function lowValueLimitCents(): int
    {
        return $this->rules->parameterFor('EUE-051', 'GWG-Grenze', 2026, 'cents');
    }

    /**
     * EUE-053: die zulässigen AfA-Kennzahlen stehen im gepinnten Regeltext,
     * nicht in einer zweiten Hardcoded-Liste. Der Text nennt genau vier
     * vierstellige KZ; andere Zahlen darin sind einstellige Mengenvergleiche.
     *
     * @return list<string>
     */
    public function afaCodes(): array
    {
        preg_match_all('/\b\d{4}\b/', $this->rules->ruleLogic('EUE-053'), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    public function minimumBusinessSharePercent(): int
    {
        return $this->rules->parameterFor('EUE-054', 'Mindest-Betriebsanteil', 2026, 'fraction-percent');
    }

    public function usefulLifeMinimum(): int
    {
        return $this->rules->parameterFor('EUE-052', 'Nutzungsjahre Minimum', 2026, 'count');
    }

    public function usefulLifeMaximum(): int
    {
        return $this->rules->parameterFor('EUE-052', 'Nutzungsjahre Maximum', 2026, 'count');
    }

    /**
     * EUE-051: unter der GWG-Grenze ist die Soforterfassung im A-Blatt zulässig.
     * Genau 1.000 € löst keinen Hinweis aus (assets-requirements.md §3).
     */
    public function isLowValueAsset(int $costCents): bool
    {
        return $costCents > 0 && $costCents < $this->lowValueLimitCents();
    }

    /**
     * EUE-054/EUE-030: 50–100 % Betriebsanteil in 5-%-Schritten.
     */
    public function isBusinessShareValid(int $percent): bool
    {
        return $percent >= $this->minimumBusinessSharePercent()
            && $percent <= 100
            && $percent % 5 === 0;
    }

    /**
     * EUE-049: lineare Jahres-AfA, halb bei Anschaffung in der zweiten Jahreshälfte.
     */
    public function linearAnnualCents(int $costCents, int $usefulLifeYears, int $acquisitionMonth, bool $isFirstYear = true): int
    {
        if ($costCents <= 0 || $usefulLifeYears <= 0) {
            return 0;
        }

        $annual = Money::divideCents($costCents, $usefulLifeYears);

        if ($isFirstYear && $acquisitionMonth >= 7) {
            return Money::applyShare($annual, '0.5');
        }

        return $annual;
    }

    /**
     * EUE-050: Restbuchwert ist Kosten minus kumulierte AfA, mindestens 0,01 €.
     */
    public function residualCents(int $costCents, int $cumulativeDepreciationCents): int
    {
        return max($costCents - $cumulativeDepreciationCents, 1);
    }

    /**
     * DEG-003/DEG-005: degressive AfA eines Jahres auf den Restbuchwert.
     */
    public function degressiveAnnualCents(int $bookValueCents, int $ratePer10000, bool $isFirstYear, int $acquisitionMonth): int
    {
        if ($bookValueCents <= 0) {
            return 0;
        }

        $annual = Money::applyShare($bookValueCents, bcdiv((string) $ratePer10000, '10000', 8));

        if ($isFirstYear && $acquisitionMonth >= 7) {
            return Money::applyShare($annual, '0.5');
        }

        return $annual;
    }

    public function isDegressiveRateValid(int $ratePer10000): bool
    {
        return $ratePer10000 >= 100 && $ratePer10000 <= 3000;
    }

    /**
     * @return list<array{year: int, method: string, depreciation_cents: int, book_value_cents: int}>
     */
    public function linearPlan(int $costCents, int $usefulLifeYears, int $acquisitionMonth): array
    {
        if ($usefulLifeYears <= 0) {
            return [];
        }

        $plan = [];
        $book = $costCents;

        for ($year = 1; $year <= $usefulLifeYears; $year++) {
            $depreciation = $year === $usefulLifeYears
                ? max($book - 1, 0)
                : min($this->linearAnnualCents($costCents, $usefulLifeYears, $acquisitionMonth, $year === 1), max($book - 1, 0));

            $book -= $depreciation;
            $plan[] = [
                'year' => $year,
                'method' => self::METHOD_LINEAR,
                'depreciation_cents' => $depreciation,
                'book_value_cents' => $book,
            ];
        }

        return $plan;
    }

    /**
     * DEG-001 … DEG-010: degressiver Plan, optional mit einmaligem Wechsel
     * zur linearen AfA ab dem angegebenen Jahr (Restbuchwert auf die
     * Restjahre verteilt). Das letzte Jahr schreibt den Rest ab (DEG-004).
     *
     * @return list<array{year: int, method: string, depreciation_cents: int, book_value_cents: int}>
     */
    public function degressivePlan(
        int $costCents,
        int $usefulLifeYears,
        int $ratePer10000,
        int $acquisitionMonth,
        ?int $switchYear = null,
    ): array {
        if ($usefulLifeYears <= 0) {
            return [];
        }

        if (! $this->isDegressiveRateValid($ratePer10000)) {
            throw new InvalidArgumentException(sprintf('Degressiver Satz außerhalb 1–30 %%: %d Basispunkte.', $ratePer10000));
        }

        if ($switchYear !== null && ($switchYear < 2 || $switchYear > $usefulLifeYears)) {
            throw new InvalidArgumentException('Wechseljahr liegt außerhalb der Nutzungsdauer.');
        }

        $plan = [];
        $book = $costCents;
        $method = self::METHOD_DEGRESSIVE;

        for ($year = 1; $year <= $usefulLifeYears; $year++) {
            if ($switchYear !== null && $year === $switchYear) {
                $method = self::METHOD_LINEAR;
            }

            $remainingYears = $usefulLifeYears - $year + 1;

            if ($year === $usefulLifeYears) {
                $depreciation = max($book - 1, 0);
            } elseif ($method === self::METHOD_LINEAR) {
                $depreciation = Money::divideCents($book, $remainingYears);
                $depreciation = min($depreciation, max($book - 1, 0));
            } else {
                $depreciation = $this->degressiveAnnualCents($book, $ratePer10000, $year === 1, $acquisitionMonth);
                $depreciation = min($depreciation, max($book - 1, 0));
            }

            $book -= $depreciation;
            $plan[] = [
                'year' => $year,
                'method' => $method,
                'depreciation_cents' => $depreciation,
                'book_value_cents' => $book,
            ];
        }

        return $plan;
    }

    /**
     * DEG-011: erstes Jahr, in dem die lineare AfA auf den Restbuchwert
     * mindestens so hoch ist wie die degressive.
     */
    public function recommendedSwitchYear(
        int $costCents,
        int $usefulLifeYears,
        int $ratePer10000,
        int $acquisitionMonth,
    ): ?int {
        $plan = $this->degressivePlan($costCents, $usefulLifeYears, $ratePer10000, $acquisitionMonth);

        for ($index = 1; $index < count($plan); $index++) {
            $year = $plan[$index]['year'];
            $remainingYears = $usefulLifeYears - $year + 1;

            if ($remainingYears <= 1) {
                break;
            }

            $linear = Money::divideCents($plan[$index - 1]['book_value_cents'], $remainingYears);
            $degressive = $plan[$index]['depreciation_cents'];

            if ($linear >= $degressive) {
                return $year;
            }
        }

        return null;
    }

    /**
     * DEG-011: maximaler kumulierter Vorteil des empfohlenen Wechsels
     * gegenüber dem durchgehend degressiven Plan, in Cent.
     */
    public function switchAdvantageCents(
        int $costCents,
        int $usefulLifeYears,
        int $ratePer10000,
        int $acquisitionMonth,
        ?int $switchYear = null,
    ): int {
        $switchYear ??= $this->recommendedSwitchYear($costCents, $usefulLifeYears, $ratePer10000, $acquisitionMonth);

        if ($switchYear === null) {
            return 0;
        }

        $without = $this->degressivePlan($costCents, $usefulLifeYears, $ratePer10000, $acquisitionMonth);
        $with = $this->degressivePlan($costCents, $usefulLifeYears, $ratePer10000, $acquisitionMonth, $switchYear);

        $cumulative = 0;
        $maximum = 0;

        foreach ($with as $index => $row) {
            $cumulative += $row['depreciation_cents'] - $without[$index]['depreciation_cents'];
            $maximum = max($maximum, $cumulative);
        }

        return $maximum;
    }
}
