<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use App\Eur\Parity\Money;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\IncomeTaxTariff;
use App\Eur\Reference\YearTables;
use InvalidArgumentException;

/**
 * EAR · Monatsansicht (EUE-116, EUE-117, EUE-118, EUE-124, EUE-129).
 *
 * Orchestriert die reinen Fachdienste zu einer Monatsansicht aus einfachen
 * Arrays: Zeilen E/A/WEB/FR, Summen, Vollständigkeitsprüfungen, SV-Werte aus
 * YearTables, eine Einkommensteuer-Schätzung über IncomeTaxTariff sowie
 * Berichts- und Signatur-Platzhalter. Keine Datenbank, keine Uhr, kein Float.
 */
final class MonthStatement
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $warehouse
     * @param  list<array<string, mixed>>  $hours
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function build(
        int $year,
        int $month,
        array $entries,
        array $warehouse = [],
        array $hours = [],
        array $context = [],
    ): array {
        EurYear::assert($year);

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(sprintf('Ungültiger Monat: %d.', $month));
        }

        $monthEntries = array_values(array_filter(
            $entries,
            static fn (mixed $entry): bool => is_array($entry)
                && self::inMonth($entry, $month),
        ));
        $monthHours = array_values(array_filter(
            $hours,
            static fn (mixed $entry): bool => is_array($entry) && self::inMonth($entry, $month),
        ));

        $incomeEntries = array_values(array_filter(
            $monthEntries,
            static fn (array $entry): bool => self::direction($entry) === 'income',
        ));
        $expenseEntries = array_values(array_filter(
            $monthEntries,
            static fn (array $entry): bool => self::direction($entry) === 'expense',
        ));

        $statement = E1Statement::fromEntries($monthEntries, $year);
        $warehouseCheck = WarehouseReconciliation::reconcile($warehouse, $expenseEntries);
        $hoursSummary = HoursLedger::summarize($monthHours);
        $vat = VatReturnService::forMonth($monthEntries);

        $lines = array_merge(
            self::journalLines('E', $incomeEntries),
            self::journalLines('A', $expenseEntries),
            self::warehouseLines($warehouse, $warehouseCheck['web_hints']),
            self::hourLines($hoursSummary['by_project']),
        );

        // EUE-034: Vermietung zählt zur E1B, nicht zu den Betriebssummen.
        $incomeCents = self::sumBusiness(self::withoutE1b($incomeEntries));
        $expenseCents = self::sumBusiness(self::withoutE1b($expenseEntries));
        $warehouseCents = self::sumAmounts($warehouse);

        $totals = [
            'income_cents' => $incomeCents,
            'expense_cents' => $expenseCents,
            'warehouse_cents' => $warehouseCents,
            'vat_cents' => $vat['zahllast_cents'] ?? 0,
            'hours_minutes' => $hoursSummary['total_minutes'],
        ];

        $checks = self::checks($statement, $totals, $warehouseCheck, $hoursSummary);
        $profitCents = $incomeCents - $expenseCents;
        $tariff = self::tariff($year);
        $allowance = $tariff->basicAllowanceCents($profitCents);

        return [
            'year' => $year,
            'month' => $month,
            'lines' => $lines,
            'totals' => $totals,
            'checks' => $checks,
            'e1_by_code' => E1Statement::byCode($monthEntries, $year),
            'social_insurance' => [
                'amounts_cents' => YearTables::socialInsuranceAmountsCents($year),
                'rates_per_10000' => YearTables::socialInsuranceRatesPer10000($year),
            ],
            'income_tax' => [
                'profit_cents' => $profitCents,
                'tax_cents' => $tariff->taxCents($profitCents - $allowance),
                'basic_allowance_cents' => $allowance,
            ],
            'report' => [
                'status' => is_string($context['report_status'] ?? null) ? $context['report_status'] : 'open',
                'closed_at' => $context['closed_at'] ?? null,
            ],
            'signature' => self::signature($context),
            'hint' => JournalRules::headerHint(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function journalLines(string $type, array $entries): array
    {
        $lines = [];

        foreach ($entries as $entry) {
            $checks = JournalRules::checks($entry);
            $lines[] = [
                'type' => $type,
                'day' => $entry['day'] ?? null,
                'description' => $entry['description'] ?? '',
                'amount_cents' => $entry['amount_cents'] ?? 0,
                'business_amount_cents' => self::businessAmountCents($entry),
                'code' => $entry['code'] ?? $entry['kennzahl'] ?? $entry['e1a_code'] ?? null,
                'vat_kind' => $entry['vat_kind'] ?? null,
                'receipt_no' => $entry['receipt_no'] ?? $entry['voucher_no'] ?? null,
                'checks' => $checks,
                'warnings' => array_values(array_filter(
                    $checks,
                    static fn (array $check): bool => ! $check['ok'],
                )),
                'hints' => array_values(array_map(
                    static fn (array $check): string => $check['hint'],
                    $checks,
                )),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $warehouse
     * @param  list<array<string, mixed>>  $hints
     * @return list<array<string, mixed>>
     */
    private static function warehouseLines(array $warehouse, array $hints): array
    {
        $lines = [];

        foreach ($warehouse as $index => $row) {
            $hint = $hints[$index] ?? JournalRules::check(
                'WEB',
                true,
                'Wareneingang übernommen.',
                'Kein Abgleichhinweis vorhanden.',
            );
            $lines[] = [
                'type' => 'WEB',
                'day' => $row['day'] ?? null,
                'description' => $row['description'] ?? $row['goods_description'] ?? '',
                'amount_cents' => $row['amount_cents'] ?? 0,
                'business_amount_cents' => $row['amount_cents'] ?? 0,
                'vat_kind' => null,
                'receipt_no' => $row['receipt_no'] ?? $row['voucher_no'] ?? null,
                'checks' => [$hint],
                'warnings' => $hint['ok'] ? [] : [$hint],
                'hints' => [$hint['hint']],
            ];
        }

        return $lines;
    }

    /** @param array<string, int> $byProject */
    private static function hourLines(array $byProject): array
    {
        $lines = [];

        foreach ($byProject as $project => $minutes) {
            $check = HoursLedger::threeHourCheck($minutes);
            $lines[] = [
                'type' => 'FR',
                'day' => null,
                'description' => $project === '' ? 'Stundenbuch' : 'Stunden: '.$project,
                'amount_cents' => 0,
                'business_amount_cents' => 0,
                'hours_minutes' => $minutes,
                'vat_kind' => null,
                'receipt_no' => null,
                'checks' => [$check],
                'warnings' => $check['ok'] ? [] : [$check],
                'hints' => [$check['hint']],
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $warehouseCheck
     * @param  array<string, mixed>  $hoursSummary
     * @return list<array{code: string, ok: bool, message: string, hint: string}>
     */
    private static function checks(array $statement, array $totals, array $warehouseCheck, array $hoursSummary): array
    {
        $e1aIncome = $statement['income_cents'][E1Statement::GROUP_E1A];
        $e1aExpense = $statement['expense_cents'][E1Statement::GROUP_E1A];
        $e1bTotal = $statement['totals_cents'][E1Statement::GROUP_E1B];

        return [
            self::reconciliationCheck(
                'BE-ART',
                'Betriebseinnahmen gegen E1A',
                $e1aIncome,
                $totals['income_cents'],
            ),
            self::reconciliationCheck(
                'BA-ART',
                'Betriebsausgaben gegen E1A',
                $e1aExpense,
                $totals['expense_cents'],
            ),
            self::reconciliationCheck(
                'ZA',
                'Zwischenergebnis gegen E1A-Ergebnis',
                $e1aIncome - $e1aExpense,
                $totals['income_cents'] - $totals['expense_cents'],
            ),
            JournalRules::check(
                'E1A-E1B',
                ($statement['complete'][E1Statement::GROUP_E1A] || $totals['income_cents'] + $totals['expense_cents'] === 0)
                    && ($statement['complete'][E1Statement::GROUP_E1B] || $e1bTotal === 0)
                    && $warehouseCheck['all_ok'],
                sprintf('E1A/E1B aufgeteilt: E1B %d ct, Wareneingang %d ct, Stunden %d Minuten.', $e1bTotal, $totals['warehouse_cents'], $totals['hours_minutes']),
                'Bitte E1B-Kennzahlen und Wareneingänge prüfen, damit die Summen vollständig sind.',
            ),
        ];
    }

    /** @return array{code: string, ok: bool, message: string, hint: string} */
    private static function reconciliationCheck(string $code, string $label, int $expected, int $actual): array
    {
        $delta = $actual - $expected;

        return JournalRules::check(
            $code,
            $delta === 0,
            $delta === 0
                ? sprintf('%s stimmt überein (%d ct).', $label, $actual)
                : sprintf('%s weicht um %d ct ab ( erwartet %d ct, tatsächlich %d ct ).', $label, abs($delta), $expected, $actual),
            $delta === 0
                ? 'Keine Korrektur nötig.'
                : 'Bitte die betroffenen Buchungen und Kennzahlen prüfen und die Differenz ausgleichen.',
        );
    }

    private static function tariff(int $year): IncomeTaxTariff
    {
        EurYear::assert($year);

        $stages = array_map(
            static fn (array $stage): array => [
                'grenze' => bcdiv((string) $stage['grenze'], '100', 2),
                'satz' => bcdiv((string) $stage['satz'], '10000', 8),
            ],
            YearTables::tariffStages($year),
        );
        $basic = YearTables::basicAllowance($year);

        return new IncomeTaxTariff(
            $stages,
            [
                'grenze' => bcdiv((string) $basic['grenze'], '100', 2),
                'satz' => bcdiv((string) $basic['satz'], '10000', 8),
            ],
            $year,
        );
    }

    /** @param array<string, mixed> $context */
    private static function signature(array $context): array
    {
        $provider = is_string($context['signature_provider'] ?? null) ? $context['signature_provider'] : null;
        $status = is_string($context['signature_status'] ?? null) ? $context['signature_status'] : 'none';

        return [
            'status' => $status,
            'provider' => $provider,
            'is_test' => $provider !== null && str_contains(strtolower($provider), 'test'),
        ];
    }

    /** @param array<string, mixed> $entry */
    private static function inMonth(array $entry, int $month): bool
    {
        $resolved = VatReturnService::monthOf($entry);

        return $resolved === null || $resolved === $month;
    }

    /** @param list<array<string, mixed>> $entries */
    private static function withoutE1b(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static function (array $entry): bool {
                foreach (['code', 'kennzahl', 'e1a_code'] as $key) {
                    $value = $entry[$key] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        return ! JournalRules::isRental($value)
                            && ! str_starts_with(strtoupper(trim($value)), 'E1B');
                    }
                }

                return true;
            },
        ));
    }

    /** @param array<string, mixed> $entry */
    private static function direction(array $entry): string
    {
        $raw = strtolower(trim((string) ($entry['kind'] ?? $entry['type'] ?? '')));

        return in_array($raw, ['income', 'e', 'einnahme', 'einnahmen'], true) ? 'income' : 'expense';
    }

    /** @param list<array<string, mixed>> $rows */
    private static function sumAmounts(array $rows): int
    {
        $sum = 0;

        foreach ($rows as $row) {
            $sum += (int) ($row['amount_cents'] ?? 0);
        }

        return $sum;
    }

    /** @param list<array<string, mixed>> $entries */
    private static function sumBusiness(array $entries): int
    {
        $sum = 0;

        foreach ($entries as $entry) {
            $sum += self::businessAmountCents($entry);
        }

        return $sum;
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
}
