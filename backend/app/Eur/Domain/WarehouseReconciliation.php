<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use InvalidArgumentException;

/**
 * EAR · Wareneingang ↔ Ausgabenabgleich (EUE-045 … EUE-047, EUE-129).
 *
 * Reiner Vergleich zweier Zeilenlisten: Wareneingänge werden zuerst über die
 * Belegnummer, sonst über Beschreibung + Betrag einer Ausgabe zugeordnet.
 * Jede Ausgabe wird höchstens einmal verbucht. Geldbeträge sind ganze Cent,
 * alle Hinweise deutsch mit ⚠ bzw. 💡. Keine Datenbank, keine Uhr, kein Float.
 */
final class WarehouseReconciliation
{
    /**
     * @param  list<array<string, mixed>>  $warehouse
     * @param  list<array<string, mixed>>  $expenses
     * @return array{
     *     matched_cents: int,
     *     unmatched_cents: int,
     *     matched: list<array<string, mixed>>,
     *     unmatched: list<array<string, mixed>>,
     *     web_hints: list<array{code: string, ok: bool, message: string, hint: string}>,
     *     hints: list<array{code: string, ok: bool, message: string, hint: string}>,
     *     all_ok: bool
     * }
     */
    public static function reconcile(array $warehouse, array $expenses): array
    {
        $byReceipt = [];
        $byDescriptionAmount = [];

        foreach ($expenses as $index => $expense) {
            if (! is_array($expense)) {
                throw new InvalidArgumentException('Ausgabezeile muss ein Array sein.');
            }

            $receipt = self::receiptOf($expense);
            $key = self::descriptionKey($expense).'|'.self::amountOf($expense);

            if ($receipt !== '') {
                $byReceipt[$receipt][] = $index;
            }

            $byDescriptionAmount[$key][] = $index;
        }

        $used = [];
        $matched = [];
        $unmatched = [];
        $hints = [];
        $matchedCents = 0;
        $unmatchedCents = 0;

        foreach ($warehouse as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Wareneingangszeile muss ein Array sein.');
            }

            $cents = self::amountOf($row);
            $receipt = self::receiptOf($row);
            $expenseIndex = self::take($byReceipt, $receipt, $used);
            $by = 'receipt_no';

            if ($expenseIndex === null) {
                $key = self::descriptionKey($row).'|'.$cents;
                $expenseIndex = self::take($byDescriptionAmount, $key, $used);
                $by = 'description+amount';
            }

            if ($expenseIndex !== null) {
                $matchedCents += $cents;
                $matched[] = [
                    'warehouse' => $row,
                    'expense' => $expenses[$expenseIndex],
                    'cents' => $cents,
                    'by' => $by,
                ];
                $hints[] = JournalRules::check(
                    'WEB',
                    true,
                    sprintf('Wareneingang über %s zugeordnet (%d ct).', $by, $cents),
                    'Der Wareneingang ist mit einer Ausgabe im A-Blatt abgeglichen.',
                );

                continue;
            }

            $unmatchedCents += $cents;
            $unmatched[] = ['warehouse' => $row, 'cents' => $cents];
            $hints[] = JournalRules::check(
                'WEB',
                false,
                sprintf('Wareneingang "%s" (%d ct) ohne passende Ausgabe.', self::descriptionOf($row), $cents),
                $receipt === ''
                    ? 'Bitte eine Belegnummer vergeben und die Ausgabe im A-Blatt erfassen.'
                    : sprintf('Bitte die Ausgabe mit Belegnummer %s im A-Blatt prüfen oder Beschreibung und Betrag korrigieren.', $receipt),
            );
        }

        return [
            'matched_cents' => $matchedCents,
            'unmatched_cents' => $unmatchedCents,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'web_hints' => $hints,
            'hints' => $hints,
            'all_ok' => $unmatched === [],
        ];
    }

    /**
     * Erste noch nicht verbrauchte Ausgabe zu einem Suchschlüssel finden.
     *
     * @param  array<string, list<int>>  $index
     * @param  array<int, true>  $used
     */
    private static function take(array $index, string $key, array &$used): ?int
    {
        if ($key === '') {
            return null;
        }

        foreach ($index[$key] ?? [] as $candidate) {
            if (! isset($used[$candidate])) {
                $used[$candidate] = true;

                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private static function receiptOf(array $row): string
    {
        $receipt = $row['receipt_no'] ?? $row['voucher_no'] ?? '';

        return is_string($receipt) ? trim($receipt) : '';
    }

    /** @param array<string, mixed> $row */
    private static function descriptionOf(array $row): string
    {
        $description = $row['description'] ?? $row['goods_description'] ?? '';

        return is_string($description) ? trim($description) : '';
    }

    /** @param array<string, mixed> $row */
    private static function descriptionKey(array $row): string
    {
        $description = mb_strtolower(self::descriptionOf($row), 'UTF-8');

        return $description === '' ? '' : preg_replace('/\s+/u', ' ', $description) ?? $description;
    }

    /** @param array<string, mixed> $row */
    private static function amountOf(array $row): int
    {
        $business = $row['business_amount_cents'] ?? null;

        if (is_int($business)) {
            return $business;
        }

        $amount = $row['amount_cents'] ?? null;

        if (! is_int($amount)) {
            throw new InvalidArgumentException('Betrag muss ein ganzzahliger Centbetrag sein.');
        }

        return $amount;
    }
}
