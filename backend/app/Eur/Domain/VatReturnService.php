<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Reference\EurYear;
use InvalidArgumentException;

/**
 * EAR · USt-Voranmeldung aus Buchungszeilen (EUE-082 … EUE-088).
 *
 * Dieser Service ist der reine Adapter vom Journal zu den ganzzahligen Cent-
 * Eingaben des USt-Rechenkerns. Alle acht Kennzahlen, die Zahllast (gegen null
 * abgerundet) sowie die Monats-, Quartals- und Jahresgruppen entstehen in
 * `VatCalculator`; hier wird nur zugeordnet und aggregiert. Kein Float, keine
 * Datenbank, keine Uhr. Für die USt-Sätze gibt es keine jahresabhängigen
 * Werte in YearTables; das Jahr wird dennoch strikt geprüft.
 */
final class VatReturnService
{
    /** Die acht Kennzahlen in Formularreihenfolge. */
    public const CODES = ['000', '016', '057', '070', '072', '073', '008', '125'];

    /** Kennzahl → exakter Bruch des Steuersatzes (kein Float). */
    public const IG_RATES = [
        '072' => ['num' => 20, 'den' => 100],
        '073' => ['num' => 10, 'den' => 100],
        '008' => ['num' => 13, 'den' => 100],
        '125' => ['num' => 49, 'den' => 1000],
    ];

    /** @param list<array<string, mixed>> $entries */
    public static function forMonth(array $entries): array
    {
        $raw = (new VatCalculator)->forMonth(self::inputsFromEntries($entries));
        $kennzahlen = [];

        foreach (self::CODES as $code) {
            $kennzahlen[$code] = $raw[$code] ?? null;
        }

        return [
            'kennzahlen' => $kennzahlen,
            'zahllast_cents' => $raw['zahllast'] ?? null,
            'empty' => self::allBlank($kennzahlen),
        ];
    }

    /**
     * EUE-087: zwölf Monate, vier Quartale und die Jahresgruppe.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     year: int,
     *     months: array<int, array<string, int|null>>,
     *     quarters: array<int, array<string, int|null>>,
     *     year_totals: array<string, int|null>
     * }
     */
    public static function forYear(int $year, array $entries): array
    {
        EurYear::assert($year);

        $byMonth = array_fill(1, 12, []);

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $month = self::monthOf($entry);

            if ($month !== null) {
                $byMonth[$month][] = $entry;
            }
        }

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = self::calculateMonth(self::inputsFromEntries($byMonth[$month]));
        }

        $aggregated = (new VatCalculator)->aggregate($months);

        return [
            'year' => $year,
            'months' => $months,
            'quarters' => $aggregated['quarters'],
            'year_totals' => $aggregated['year'],
        ];
    }

    /**
     * Quartals- und Jahresgruppen für einen einzelnen bebuchten Monat.
     *
     * Der EQV-Endpunkt erhält einen Monat; die nicht bebuchten Monate bleiben
     * leer (null), genau wie in `VatCalculator::sum()`. Die Aggregation selbst
     * bleibt beim Rechenkern, es entsteht keine zweite Summierlogik.
     *
     * @param  array<string, int|null>  $monthResult
     * @return array{quarters: array<int, array<string, int|null>>, year_totals: array<string, int|null>}
     */
    public static function groupMonth(int $month, array $monthResult): array
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(sprintf('Ungültiger Monat: %d.', $month));
        }

        $months = array_fill(1, 12, []);
        $months[$month] = $monthResult;
        $aggregated = (new VatCalculator)->aggregate($months);

        return [
            'quarters' => $aggregated['quarters'],
            'year_totals' => $aggregated['year'],
        ];
    }

    /**
     * @param  array{small_business_income_cents?: int|null, reverse_charge_cents?: int|null, ig_bases_cents?: array<int, int|null>}  $inputs
     * @return array<string, int|null>
     */
    public static function calculateMonth(array $inputs): array
    {
        return (new VatCalculator)->forMonth($inputs);
    }

    /**
     * Journal → Eingaben des Rechenkerns: Einnahmen → KZ 016, Reverse Charge →
     * KZ 057 (Vorsteuer aus Brutto), IG-Erwerbe → die vier Bemessungsgrundlagen.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{small_business_income_cents: int|null, reverse_charge_cents: int|null, ig_bases_cents: array<int, int>}
     */
    public static function inputsFromEntries(array $entries): array
    {
        $income = 0;
        $reverse = 0;
        $bases = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $amount = self::businessAmountCents($entry);

            if (self::direction($entry) === 'income') {
                // EUE-034: Mieteinnahmen gehören in die E1B, nicht in KZ 016.
                if (! self::isRentalEntry($entry)) {
                    $income += $amount;
                }

                continue;
            }

            $vatKind = trim((string) ($entry['vat_kind'] ?? ''));

            if (isset(JournalRules::RC_RATES[$vatKind])) {
                $reverse += JournalRules::vatCents($amount, $vatKind)['rc_vat_cents'];

                continue;
            }

            if (isset(JournalRules::IG_RATES[$vatKind])) {
                $rate = self::igRate($vatKind);
                $bases[$rate] = ($bases[$rate] ?? 0) + $amount;
            }
        }

        // EUE-087: ein nicht bebuchter Wert bleibt leer (null), nicht 0.
        return [
            'small_business_income_cents' => $income !== 0 ? $income : null,
            'reverse_charge_cents' => $reverse !== 0 ? $reverse : null,
            'ig_bases_cents' => $bases,
        ];
    }

    /** Kalendermonat 1–12 aus `month` oder `date`/`period`, sonst null. */
    public static function monthOf(array $entry): ?int
    {
        $month = $entry['month'] ?? null;

        if (is_int($month)) {
            return $month >= 1 && $month <= 12 ? $month : null;
        }

        foreach (['date', 'period'] as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) && preg_match('/^\d{4}-(\d{2})/', $value, $matches) === 1) {
                $parsed = (int) $matches[1];

                return $parsed >= 1 && $parsed <= 12 ? $parsed : null;
            }
        }

        return null;
    }

    /** @param array<string, int|null> $kennzahlen */
    private static function allBlank(array $kennzahlen): bool
    {
        foreach ($kennzahlen as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    private static function igRate(string $vatKind): int
    {
        return match ($vatKind) {
            'IG-Erwerb 20 %' => 20,
            'IG-Erwerb 10 %' => 10,
            'IG-Erwerb 13 %' => 13,
            default => 49,
        };
    }

    /** @param array<string, mixed> $entry */
    private static function businessAmountCents(array $entry): int
    {
        $business = $entry['business_amount_cents'] ?? null;

        if (is_int($business)) {
            return $business;
        }

        $amount = $entry['amount_cents'] ?? null;

        if (! is_int($amount)) {
            return 0;
        }

        $share = is_int($entry['business_share'] ?? null) ? (int) $entry['business_share'] : 100;

        return Money::applyShare($amount, bcdiv((string) $share, '100', 8));
    }

    /** @param array<string, mixed> $entry */
    private static function isRentalEntry(array $entry): bool
    {
        foreach (['code', 'kennzahl', 'e1a_code'] as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return JournalRules::isRental($value) || str_starts_with(strtoupper(trim($value)), 'E1B');
            }
        }

        return false;
    }

    /** @param array<string, mixed> $entry */
    private static function direction(array $entry): string
    {
        $raw = strtolower(trim((string) ($entry['kind'] ?? $entry['type'] ?? '')));

        return in_array($raw, ['income', 'e', 'einnahme', 'einnahmen'], true) ? 'income' : 'expense';
    }
}
