<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Parity\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * EAR · Einkommensteuertarif (EUE-089 … EUE-098).
 *
 * Die Stufen kommen aus dem gepinnten Referenzartefakt. Die erste Grenze ist
 * die steuerfreie Schwelle; jede weitere Stufe rechnet den Anteil des
 * Einkommens zwischen unterer und oberer Grenze mit ihrem Satz (EUE-094).
 * Gerechnet wird durchgehend mit ganzen Cent und BCMath; gerundet wird einmal
 * am Ende kaufmännisch.
 */
final class IncomeTaxTariff
{
    /** @var list<array{from_cents: int, to_cents: int|null, rate_per_10000: int}> */
    private array $stages = [];

    private readonly int $basicAllowanceLimitCents;

    private readonly int $basicAllowanceRatePer10000;

    /**
     * @param  list<array{grenze: int|float, satz: int|float}>  $stages
     * @param  array{grenze: int|float, satz: int|float}  $basicAllowance
     */
    public function __construct(array $stages, array $basicAllowance, private readonly int $businessYear)
    {
        if ($stages === []) {
            throw new InvalidArgumentException('Einkommensteuertarif ohne Stufen.');
        }

        // Das Artefakt nennt je Stufe die untere Grenze und den Satz; die obere
        // Grenze ist die untere Grenze der nächsten Stufe (EUE-089 … EUE-093).
        $bounds = [];
        $rates = [];

        foreach ($stages as $stage) {
            $bounds[] = Money::eurosToCents($stage['grenze'] ?? 0);
            $rates[] = Money::fractionToPer10000($stage['satz'] ?? 0);
        }

        $count = count($bounds);

        for ($index = 0; $index < $count; $index++) {
            $this->stages[] = [
                'from_cents' => $bounds[$index],
                'to_cents' => $bounds[$index + 1] ?? null,
                'rate_per_10000' => $rates[$index],
            ];
        }

        $this->basicAllowanceLimitCents = Money::eurosToCents($basicAllowance['grenze'] ?? 0);
        $this->basicAllowanceRatePer10000 = Money::fractionToPer10000($basicAllowance['satz'] ?? 0);
    }

    /**
     * EUE-089 … EUE-093: Tarif aus den jahresabhängigen Tabellen aufbauen.
     *
     * `ReferenceTables::incomeTax()` ist auf das Wirtschaftsjahr 2026 gepinnt;
     * dieser Factory liefert denselben Tarif für jedes unterstützte Jahr, ohne
     * eine zweite Regelquelle oder Float-Geldwerte im Rechenpfad.
     */
    public static function forYear(int $year): self
    {
        EurYear::assert($year);

        $stages = array_map(
            static fn (array $stage): array => [
                'grenze' => bcdiv((string) $stage['grenze'], '100', 2),
                'satz' => bcdiv((string) $stage['satz'], '10000', 6),
            ],
            YearTables::tariffStages($year),
        );

        $allowance = YearTables::basicAllowance($year);

        return new self(
            $stages,
            [
                'grenze' => bcdiv((string) $allowance['grenze'], '100', 2),
                'satz' => bcdiv((string) $allowance['satz'], '10000', 6),
            ],
            $year,
        );
    }

    public function businessYear(): int
    {
        return $this->businessYear;
    }

    /**
     * @return list<array{from_cents: int, to_cents: int|null, rate_per_10000: int}>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    /**
     * Steuerfreie Schwelle (erste Tarifgrenze, EUE-089).
     */
    public function taxFreeThresholdCents(): int
    {
        return $this->stages[0]['from_cents'];
    }

    /**
     * Progressionssteuer auf das Gesamteinkommen in Cent (EUE-094).
     */
    public function taxCents(int $incomeCents): int
    {
        if ($incomeCents <= 0) {
            return 0;
        }

        $tax = '0';

        foreach ($this->stages as $stage) {
            $from = $stage['from_cents'];
            $to = $stage['to_cents'];

            $portion = bcsub(
                (string) max($incomeCents - $from, 0),
                (string) ($to === null ? 0 : max($incomeCents - $to, 0)),
                2,
            );

            if (bccomp($portion, '0', 2) <= 0) {
                continue;
            }

            $tax = bcadd($tax, bcmul($portion, bcdiv((string) $stage['rate_per_10000'], '10000', 8), 8), 8);
        }

        return Money::roundCents($tax);
    }

    /**
     * @return array{limit_cents: int, rate_per_10000: int, maximum_cents: int}
     */
    public function basicAllowance(): array
    {
        return [
            'limit_cents' => $this->basicAllowanceLimitCents,
            'rate_per_10000' => $this->basicAllowanceRatePer10000,
            'maximum_cents' => Money::applyShare(
                $this->basicAllowanceLimitCents,
                bcdiv((string) $this->basicAllowanceRatePer10000, '10000', 8),
            ),
        ];
    }

    /**
     * Grundfreibetrag (EUE-098): min(Gewinn × Satz, Grenze × Satz), mindestens 0.
     */
    public function basicAllowanceCents(int $profitCents): int
    {
        if ($profitCents <= 0) {
            return 0;
        }

        $rate = bcdiv((string) $this->basicAllowanceRatePer10000, '10000', 8);
        $byProfit = Money::applyShare($profitCents, $rate);
        $maximum = $this->basicAllowance()['maximum_cents'];

        return max(0, min($byProfit, $maximum));
    }

    public static function assertYear(int $year): void
    {
        if ($year !== ReferenceTables::BUSINESS_YEAR) {
            throw new RuntimeException(sprintf(
                'Nur das Wirtschaftsjahr %d ist gepinnt, erhalten: %d.',
                ReferenceTables::BUSINESS_YEAR,
                $year,
            ));
        }
    }
}
