<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Reference\E1aHelpTexts;
use App\Eur\Reference\EurYear;
use InvalidArgumentException;

/**
 * EAR · E1A/E1B-Aggregation (EUE-095, EUE-099, EUE-100).
 *
 * Reine Buchungszeilen → Kennzahlen-Summen in ganzen Cent. Vermietungszeilen
 * (E1B-Präfix oder 9460) werden aus dem Betriebsergebnis herausgerechnet und
 * in die E1B-Gruppe geführt. Kein Datenbankzugriff, keine Uhr, kein Float.
 */
final class E1Statement
{
    public const GROUP_E1A = 'E1A';

    public const GROUP_E1B = 'E1B';

    /** EUE-024: die vier Betriebseinnahmen-Kennzahlen in ODS-Reihenfolge. */
    private const INCOME_CODES = ['9040', '9050', '9060', '9090'];

    /** EUE-101/102: Pauschalen sind Eingabefelder, keine Journal-Kennzahlen. */
    private const INPUT_CODES = ['9216', '9215', '9217'];

    /** E1B!C10:C15 (EUE-100) — 9135 ist bewusst auch hier geführt. */
    private const E1B_CODES = ['9500', '9135', '9510', '9520', '9521', '9530'];

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     year: int,
     *     e1a: array<string, int>,
     *     e1b: array<string, int>,
     *     totals_cents: array<string, int>,
     *     income_cents: array<string, int>,
     *     expense_cents: array<string, int>,
     *     complete: array<string, bool>,
     *     flags: array<string, bool>
     * }
     */
    public static function fromEntries(array $entries, int $year = EurYear::DEFAULT): array
    {
        EurYear::assert($year);

        $result = [self::GROUP_E1A => [], self::GROUP_E1B => []];
        $income = [self::GROUP_E1A => 0, self::GROUP_E1B => 0];
        $expense = [self::GROUP_E1A => 0, self::GROUP_E1B => 0];
        $seen = [self::GROUP_E1A => false, self::GROUP_E1B => false];
        $invalid = [self::GROUP_E1A => false, self::GROUP_E1B => false];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('E1-Zeile muss ein Array sein.');
            }

            $code = self::codeFor($entry);

            if ($code === '') {
                continue; // Privatanteil ohne Kennzahl zählt nicht in E1A/E1B.
            }

            $group = JournalRules::isRental($code) || str_starts_with(strtoupper($code), 'E1B')
                ? self::GROUP_E1B
                : self::GROUP_E1A;
            $code = self::normaliseCode($code);
            $amount = self::amountFor($entry);

            if ($amount === null) {
                $invalid[$group] = true;

                continue;
            }

            $result[$group][$code] = ($result[$group][$code] ?? 0) + $amount;

            if (self::direction($entry) === 'income') {
                $income[$group] += $amount;
            } else {
                $expense[$group] += $amount;
            }

            $seen[$group] = true;
        }

        ksort($result[self::GROUP_E1A]);
        ksort($result[self::GROUP_E1B]);

        $complete = [];

        foreach ([self::GROUP_E1A, self::GROUP_E1B] as $group) {
            $complete[$group] = $seen[$group] && ! $invalid[$group];
        }

        return [
            'year' => $year,
            'e1a' => $result[self::GROUP_E1A],
            'e1b' => $result[self::GROUP_E1B],
            'totals_cents' => [
                self::GROUP_E1A => array_sum($result[self::GROUP_E1A]),
                self::GROUP_E1B => array_sum($result[self::GROUP_E1B]),
            ],
            'income_cents' => $income,
            'expense_cents' => $expense,
            'complete' => $complete,
            'flags' => $complete,
        ];
    }

    /**
     * Beträge je Kennzahl, gruppiert nach E1A und E1B (EUE-099/100).
     *
     * Dies ist dieselbe Aggregation wie `fromEntries()`, nur ohne die
     * Summen-/Vollständigkeitsfelder: die Per-KZ-Zeilen werden dadurch auch
     * an Aufrufer durchgereicht, die bisher nur die Gesamtsummen sahen.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{E1A: array<string, int>, E1B: array<string, int>}
     */
    public static function byCode(array $entries, int $year = EurYear::DEFAULT): array
    {
        $statement = self::fromEntries($entries, $year);

        return [
            self::GROUP_E1A => $statement['e1a'],
            self::GROUP_E1B => $statement['e1b'],
        ];
    }

    /**
     * D-91: Jahreswerte in ODS-Reihenfolge aus den vier gepinnten Quellen.
     *
     * `A` ist das Ausgaben-/Einnahmenjournal, `WEB` der Wareneingang, `AV` das
     * Anlagenverzeichnis und `FR` das Reiseblatt. Jede Quelle wird mit
     * `fromEntries()` aggregiert; die Kennzahlen werden anschließend per
     * Präfix-SUMIF (EUE-099/100) auf die Zeilen der Ausfüllhilfe verteilt.
     * 9040 und 9230 sind Restgrößen. Es entsteht keine neue Regel: die
     * Reihenfolge der Ausgabenzeilen kommt aus `E1aHelpTexts`, die Kataloge
     * bleiben unverändert. Nullwerte werden erst in der Antwort auf `null`
     * abgebildet; hier wird weiter in ganzen Cent gerechnet.
     *
     * @param  array{A?: list<array<string, mixed>>, AV?: list<array<string, mixed>>, WEB?: list<array<string, mixed>>, FR?: list<array<string, mixed>>}  $sources
     * @return array{
     *     year: int,
     *     sources: array<string, array<string, mixed>>,
     *     income: array<string, int>,
     *     expense: array<string, int>,
     *     e1b: array<string, int>,
     *     income_cents: int,
     *     expense_cents: int,
     *     profit_cents: int
     * }
     */
    public static function annual(array $sources, int $year = EurYear::DEFAULT): array
    {
        EurYear::assert($year);

        $perSource = [];

        foreach (['A', 'AV', 'WEB', 'FR'] as $key) {
            $rows = $sources[$key] ?? [];
            $statement = self::fromEntries(is_array($rows) ? $rows : [], $year);
            $perSource[$key] = [
                'codes' => [
                    self::GROUP_E1A => $statement['e1a'],
                    self::GROUP_E1B => $statement['e1b'],
                ],
                'income_cents' => (int) $statement['income_cents'][self::GROUP_E1A],
                'expense_cents' => (int) $statement['expense_cents'][self::GROUP_E1A],
                'e1b_income_cents' => (int) $statement['income_cents'][self::GROUP_E1B],
                'e1b_expense_cents' => (int) $statement['expense_cents'][self::GROUP_E1B],
            ];
        }

        $incomeCents = 0;
        $expenseCents = 0;
        $e1bIncomeCents = 0;
        $e1bExpenseCents = 0;

        foreach ($perSource as $source) {
            $incomeCents += $source['income_cents'];
            $expenseCents += $source['expense_cents'];
            $e1bIncomeCents += $source['e1b_income_cents'];
            $e1bExpenseCents += $source['e1b_expense_cents'];
        }

        // EUE-024: 9040 ist das Sammelbecken. E1B-Einnahmen sind durch die
        // Gruppierung in fromEntries() bereits herausgerechnet.
        $income = ['9040' => $incomeCents];

        foreach (array_slice(self::INCOME_CODES, 1) as $code) {
            $value = self::sumPrefix($perSource['A']['codes'][self::GROUP_E1A], $code);
            $income[$code] = $value;
            $income['9040'] -= $value;
        }

        $expense = [];
        $assigned = 0;

        foreach (E1aHelpTexts::all() as $help) {
            $code = $help['kz'];

            if (in_array($code, self::INCOME_CODES, true) || in_array($code, self::INPUT_CODES, true)) {
                continue;
            }

            $value = self::sumPrefix($perSource['A']['codes'][self::GROUP_E1A], $code);

            // EUE-099: WEB vollständig in 9100, FR vollständig in 9160,
            // AV auf die drei AfA-Kennzahlen verteilt.
            if ($code === '9100') {
                $value += $perSource['WEB']['expense_cents'];
            } elseif (in_array($code, ['9130', '9134', '9135'], true)) {
                $value += self::sumPrefix($perSource['AV']['codes'][self::GROUP_E1A], $code);
            } elseif ($code === '9160') {
                $value += $perSource['FR']['expense_cents'];
            }

            $expense[$code] = $value;

            // 9230 wird unten als Rest berechnet und darf hier nicht
            // zusätzlich in die bereits zugeordneten Zeilen zählen.
            if ($code !== '9230') {
                $assigned += $value;
            }
        }

        // E38: Restgröße über alle vier Quellen, nie zusätzlich zu E17:E37.
        $expense['9230'] = $expenseCents - $assigned;

        $e1b = ['9460' => $e1bIncomeCents];
        $coded = 0;

        foreach (self::E1B_CODES as $code) {
            $value = self::sumPrefix($perSource['A']['codes'][self::GROUP_E1B], $code)
                + self::sumPrefix($perSource['AV']['codes'][self::GROUP_E1B], $code);
            $e1b[$code] = $value;
            $coded += $value;
        }

        // E15 ist die Sammelzeile der übrigen E1B-Werbungskosten, E18 das
        // Vermietungsergebnis (EUE-100).
        $e1b['E15'] = $e1bExpenseCents - $coded;
        $e1b['E18'] = $e1bIncomeCents - $e1bExpenseCents;

        return [
            'year' => $year,
            'sources' => $perSource,
            'income' => $income,
            'expense' => $expense,
            'e1b' => $e1b,
            'income_cents' => $incomeCents,
            'expense_cents' => $expenseCents,
            'profit_cents' => $incomeCents - $expenseCents,
        ];
    }

    /**
     * Nettosumme aller E1A-Zeilen (Einnahmen minus Ausgaben).
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public static function e1aProfitCents(array $entries, int $year = EurYear::DEFAULT): int
    {
        EurYear::assert($year);
        $income = 0;
        $expenses = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = self::codeFor($entry);

            if ($code === '' || JournalRules::isRental($code) || str_starts_with(strtoupper($code), 'E1B')) {
                continue;
            }

            $amount = self::amountFor($entry) ?? 0;

            if (self::direction($entry) === 'income') {
                $income += $amount;
            } else {
                $expenses += $amount;
            }
        }

        return $income - $expenses;
    }

    /**
     * Präfix-SUMIF über die bereits aggregierten Kennzahlen (EUE-099/100):
     * `9100*` umfasst auch `9100 - Waren` und `9100 Sonstiges`.
     *
     * @param  array<string, int>  $codes
     */
    private static function sumPrefix(array $codes, string $prefix): int
    {
        $sum = 0;

        foreach ($codes as $code => $amount) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += $amount;
            }
        }

        return $sum;
    }

    /** @param array<string, mixed> $entry */
    private static function codeFor(array $entry): string
    {
        foreach (['code', 'kennzahl', 'e1a_code'] as $key) {
            $value = $entry[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $direction = self::direction($entry);
        $share = is_int($entry['business_share'] ?? null) ? (int) $entry['business_share'] : 100;

        return $direction === 'income'
            ? JournalRules::incomeCode($direction, $share, null)
            : JournalRules::expenseCode($direction, $share, null);
    }

    /** @param array<string, mixed> $entry */
    private static function amountFor(array $entry): ?int
    {
        $business = $entry['business_amount_cents'] ?? null;

        if ($business !== null) {
            return is_int($business) ? $business : null;
        }

        $amount = $entry['amount_cents'] ?? null;

        if (! is_int($amount)) {
            return null;
        }

        $share = is_int($entry['business_share'] ?? null) ? (int) $entry['business_share'] : 100;

        return Money::applyShare($amount, bcdiv((string) $share, '100', 8));
    }

    private static function normaliseCode(string $code): string
    {
        return trim(preg_replace('/^E1B\s*/i', '', trim($code)) ?? $code);
    }

    /** @param array<string, mixed> $entry */
    private static function direction(array $entry): string
    {
        $raw = strtolower(trim((string) ($entry['kind'] ?? $entry['type'] ?? '')));

        return in_array($raw, ['income', 'e', 'einnahme', 'einnahmen'], true) ? 'income' : 'expense';
    }
}
