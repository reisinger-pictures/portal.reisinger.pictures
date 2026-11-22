<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
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
