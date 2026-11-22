<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use InvalidArgumentException;

/**
 * EAR · Umsatzsteuer (EUE-082 … EUE-088).
 *
 * Die Kennzahlenzuordnung ist fest (EUE-082). Alle Beträge sind ganze Cent;
 * Steuerbeträge werden an der Cent-Grenze gegen null abgerundet, weil die
 * Zahllast nur auf zwei Nachkommastellen angesetzt wird (EUE-084/086).
 * Leere Monatswerte bleiben leer und werden nicht als 0 ausgegeben.
 */
final class VatCalculator
{
    public const KZ_TOTAL = '000';

    public const KZ_SMALL_BUSINESS = '016';

    public const KZ_REVERSE_CHARGE = '057';

    public const KZ_IG_TOTAL = '070';

    public const KZ_IG_20 = '072';

    public const KZ_IG_10 = '073';

    public const KZ_IG_13 = '008';

    public const KZ_IG_49 = '125';

    /** @var array<string, int> Steuersatz in Basispunkten je Kennzahl */
    private const RATES_PER_10000 = [
        self::KZ_IG_20 => 2000,
        self::KZ_IG_10 => 1000,
        self::KZ_IG_13 => 1300,
        self::KZ_IG_49 => 490,
    ];

    /**
     * @param  array{small_business_income_cents?: int|null, reverse_charge_cents?: int|null, ig_bases_cents?: array<int, int|null>}  $month
     * @return array<string, int|null>
     */
    public function forMonth(array $month): array
    {
        $income = $month['small_business_income_cents'] ?? null;
        $reverse = $month['reverse_charge_cents'] ?? null;
        $bases = $month['ig_bases_cents'] ?? [];

        $result = [
            self::KZ_TOTAL => null,
            self::KZ_SMALL_BUSINESS => null,
            self::KZ_REVERSE_CHARGE => null,
            self::KZ_IG_TOTAL => null,
            self::KZ_IG_20 => null,
            self::KZ_IG_10 => null,
            self::KZ_IG_13 => null,
            self::KZ_IG_49 => null,
            'zahllast' => null,
        ];

        if ($income !== null) {
            // EUE-083: Kleinunternehmer-Umsatz erscheint in KZ 016 und KZ 000.
            $result[self::KZ_SMALL_BUSINESS] = $income;
            $result[self::KZ_TOTAL] = $income;
        }

        if ($reverse !== null) {
            // EUE-084: Reverse Charge wird abgerundet und fließt nicht in KZ 000.
            $result[self::KZ_REVERSE_CHARGE] = $this->floorCents($reverse);
        }

        $hasBase = false;
        $baseTotal = 0;

        foreach (self::RATES_PER_10000 as $kz => $rate) {
            $value = $this->rateKey((string) $kz, $bases);

            if ($value === null) {
                continue;
            }

            $hasBase = true;
            $floor = $this->floorCents($value);
            $result[$kz] = $floor;
            $baseTotal += $floor;
        }

        if ($hasBase) {
            // EUE-085: KZ 070 ist die Summe der vier Bemessungsgrundlagen.
            $result[self::KZ_IG_TOTAL] = $baseTotal;
        }

        if ($reverse !== null || $hasBase) {
            // EUE-086: Zahllast = Reverse Charge + Satz × Bemessungsgrundlage.
            $tax = $reverse === null ? 0 : $this->floorCents($reverse);

            foreach (self::RATES_PER_10000 as $kz => $rate) {
                $value = $result[(string) $kz];

                if ($value !== null) {
                    $tax += $this->floorCents(intdiv($value * $rate, 10000));
                }
            }

            $result['zahllast'] = $tax;
        }

        return $result;
    }

    /**
     * EUE-087: Monatswerte zu vier Quartalsgruppen und einer Jahresgruppe.
     *
     * @param  array<int, array<string, int|null>>  $months  Monat 1–12 → Kennzahlen
     * @return array{quarters: array<int, array<string, int|null>>, year: array<string, int|null>}
     */
    public function aggregate(array $months): array
    {
        foreach (array_keys($months) as $month) {
            if ($month < 1 || $month > 12) {
                throw new InvalidArgumentException(sprintf('Ungültiger Monat: %d.', $month));
            }
        }

        $quarters = [];

        for ($quarter = 0; $quarter < 4; $quarter++) {
            $group = [];

            for ($month = $quarter * 3 + 1; $month <= $quarter * 3 + 3; $month++) {
                $group[] = $months[$month] ?? [];
            }

            $quarters[$quarter + 1] = $this->sum($group);
        }

        return [
            'quarters' => $quarters,
            'year' => $this->sum(array_values($months)),
        ];
    }

    /**
     * @param  list<array<string, int|null>>  $rows
     * @return array<string, int|null>
     */
    private function sum(array $rows): array
    {
        $keys = [
            self::KZ_TOTAL, self::KZ_SMALL_BUSINESS, self::KZ_REVERSE_CHARGE,
            self::KZ_IG_TOTAL, self::KZ_IG_20, self::KZ_IG_10, self::KZ_IG_13,
            self::KZ_IG_49, 'zahllast',
        ];

        $result = array_fill_keys($keys, null);

        foreach ($keys as $key) {
            $sum = null;

            foreach ($rows as $row) {
                $value = $row[$key] ?? null;

                if ($value === null) {
                    continue;
                }

                $sum = ($sum ?? 0) + $value;
            }

            $result[$key] = $sum;
        }

        return $result;
    }

    /**
     * @param  array<int, int|null>  $bases
     */
    private function rateKey(string $kz, array $bases): ?int
    {
        $rate = match ($kz) {
            self::KZ_IG_20 => 20,
            self::KZ_IG_10 => 10,
            self::KZ_IG_13 => 13,
            self::KZ_IG_49 => 49,
        };

        $value = $bases[$rate] ?? null;

        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException('Bemessungsgrundlage darf nicht negativ sein.');
        }

        return $value;
    }

    /**
     * Abrunden gegen null auf ganze Cent (EUE-084/086).
     */
    private function floorCents(int $cents): int
    {
        return $cents < 0 ? -intdiv(-$cents, 1) : $cents;
    }
}
