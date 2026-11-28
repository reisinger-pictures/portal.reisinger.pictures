<?php

declare(strict_types=1);

namespace App\Ea\Domain;

use InvalidArgumentException;

/**
 * EUE-121 · Projekte × Monat (Einnahmen, Ausgaben, Stunden, Ergebnis).
 *
 * Reine Aggregation über flache Arrays: keine Eloquent-Modelle, keine Datenbank,
 * keine Uhr, kein Float. Alle Geldbeträge sind ganze Cent, alle Zeiten ganze
 * Minuten. `result_per_hour_cents` ist bewusst Cent **pro Minute** der
 * Stunden-Summe (intdiv des Ergebnisses durch die Minuten), damit kein
 * Gleitkommawert in den Rechenpfad kommt; die Anzeige beschriftet den Wert
 * entsprechend mit "Cent pro Minute".
 *
 * Die Vorjahres-AfA erscheint nur als eigene Zahl oben: Anlagegüter haben im
 * Portal keine Projektzuordnung (D-85). Sie wird deshalb nie willkürlich auf
 * Projekte verteilt, sondern separat ausgewiesen. Einnahmen ohne Projekt und
 * Stunden ohne Projekt laufen in der Zeile "Ohne Projekt"; die ohne Projekt
 * erfassten Minuten werden zusätzlich als `undistributed_hours_minutes`
 * gemeldet, damit keine Stunden stillschweigend verloren gehen (D78).
 */
final class ProjectAnalytics
{
    private const NO_PROJECT = 'Ohne Projekt';

    /**
     * @param  list<array<string, mixed>>  $entries  Buchungen mit month, direction, project, business_amount_cents, e1a_code
     * @param  list<array<string, mixed>>  $hours  Stunden mit month, minutes, project
     * @return array<string, mixed>
     */
    public static function build(
        int $year,
        array $entries,
        array $hours,
        int $priorYearAfaCents,
        bool $excludeBookedSv,
    ): array {
        $groups = [];
        $incomeBooked = 0;
        $expenseBooked = 0;
        $svExcluded = 0;
        $totalMinutes = 0;
        $namedMinutes = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Buchungszeile muss ein Array sein.');
            }

            $cents = $entry['business_amount_cents'] ?? null;

            if (! is_int($cents)) {
                throw new InvalidArgumentException('Buchungszeile ohne ganzzahlige business_amount_cents.');
            }

            $month = $entry['month'] ?? null;

            if (! is_int($month) || $month < 1 || $month > 12) {
                throw new InvalidArgumentException('Buchungszeile ohne gültigen ganzen Monat (1–12).');
            }

            $project = self::projectName($entry['project'] ?? null);
            $groups[$project] ??= self::emptyRow($project);
            $direction = $entry['direction'] ?? null;

            if ($direction === 'income') {
                $groups[$project]['months'][$month]['income_cents'] += $cents;
                $incomeBooked += $cents;

                continue;
            }

            if ($direction !== 'expense') {
                throw new InvalidArgumentException('Buchungszeile mit unbekannter Richtung.');
            }

            if ($excludeBookedSv && str_starts_with((string) ($entry['e1a_code'] ?? ''), '9225')) {
                $svExcluded += $cents;

                continue;
            }

            $groups[$project]['months'][$month]['expense_cents'] += $cents;
            $expenseBooked += $cents;
        }

        foreach ($hours as $hour) {
            if (! is_array($hour)) {
                throw new InvalidArgumentException('Stundeneintrag muss ein Array sein.');
            }

            $minutes = $hour['hours_minutes'] ?? $hour['minutes'] ?? null;

            if (! is_int($minutes)) {
                throw new InvalidArgumentException('Stundeneintrag ohne ganzzahlige Minuten.');
            }

            $month = $hour['month'] ?? null;

            if (! is_int($month) || $month < 1 || $month > 12) {
                throw new InvalidArgumentException('Stundeneintrag ohne gültigen ganzen Monat (1–12).');
            }

            $rawProject = $hour['project'] ?? null;
            $project = self::projectName($rawProject);
            $groups[$project] ??= self::emptyRow($project);
            $groups[$project]['months'][$month]['hours_minutes'] += $minutes;
            $totalMinutes += $minutes;

            if (is_string($rawProject) && trim($rawProject) !== '') {
                $namedMinutes += $minutes;
            }
        }

        $rows = [];

        foreach ($groups as $row) {
            $rows[] = self::finalizeRow($row);
        }

        usort($rows, static fn (array $a, array $b): int => $b['totals']['income_cents'] <=> $a['totals']['income_cents']
            ?: strcmp($a['project'], $b['project']));

        $monthTotals = self::emptyMonthTotals();
        $totals = self::emptyTotals();
        $incomeRows = 0;
        $expenseRows = 0;

        foreach ($rows as $row) {
            foreach ($row['months'] as $cell) {
                $month = $cell['month'];
                $monthTotals[$month]['income_cents'] += $cell['income_cents'];
                $monthTotals[$month]['expense_cents'] += $cell['expense_cents'];
                $monthTotals[$month]['hours_minutes'] += $cell['hours_minutes'];
            }

            $incomeRows += $row['totals']['income_cents'];
            $expenseRows += $row['totals']['expense_cents'];
            $totals['income_cents'] += $row['totals']['income_cents'];
            $totals['expense_cents'] += $row['totals']['expense_cents'];
            $totals['hours_minutes'] += $row['totals']['hours_minutes'];
        }

        foreach ($monthTotals as $month => $cell) {
            $monthTotals[$month]['result_cents'] = $cell['income_cents'] - $cell['expense_cents'];
        }

        $totals['result_cents'] = $totals['income_cents'] - $totals['expense_cents'];
        $totals['result_per_hour_cents'] = $totals['hours_minutes'] > 0
            ? intdiv($totals['result_cents'], $totals['hours_minutes'])
            : null;

        $undistributed = $totalMinutes - $namedMinutes;

        return [
            'year' => $year,
            'projects' => $rows,
            'month_totals' => array_values($monthTotals),
            'totals' => $totals,
            'undistributed_hours_minutes' => $undistributed,
            'prior_year_afa_cents' => $priorYearAfaCents,
            'sv_excluded_cents' => $svExcluded,
            'checks' => [
                JournalRules::check(
                    'P121-INCOME',
                    $incomeRows === $incomeBooked,
                    sprintf('Einnahmen je Projekt und Monat ergeben %d ct.', $incomeRows),
                    'Bitte prüfen, ob alle Einnahmen ein Projekt und einen Monat haben.',
                ),
                JournalRules::check(
                    'P121-EXPENSE',
                    $expenseRows === $expenseBooked,
                    sprintf('Ausgaben je Projekt und Monat ergeben %d ct (nach 9225-Bereinigung).', $expenseRows),
                    'Bitte prüfen, ob alle Ausgaben ein Projekt und einen Monat haben.',
                ),
                JournalRules::check(
                    'P121-HOURS',
                    $undistributed >= 0,
                    sprintf('Ohne Projekt erfasste Stunden: %d Minuten.', $undistributed),
                    'Bitte prüfen, ob die Stundenbuchung gültige Monate und ganze Minuten enthält.',
                ),
            ],
            'match' => $incomeRows === $incomeBooked && $expenseRows === $expenseBooked && $undistributed >= 0,
        ];
    }

    /** @return array{project: string, months: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    private static function emptyRow(string $project): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'income_cents' => 0,
                'expense_cents' => 0,
                'hours_minutes' => 0,
                'result_cents' => 0,
                'result_per_hour_cents' => null,
            ];
        }

        return ['project' => $project, 'months' => $months, 'totals' => self::emptyTotals()];
    }

    /** @param array{project: string, months: array<int, array<string, mixed>>, totals: array<string, mixed>} $row
     * @return array<string, mixed>
     */
    private static function finalizeRow(array $row): array
    {
        $row['totals'] = self::emptyTotals();

        foreach ($row['months'] as $month => $cell) {
            $cell['result_cents'] = $cell['income_cents'] - $cell['expense_cents'];
            $cell['result_per_hour_cents'] = $cell['hours_minutes'] > 0
                ? intdiv($cell['result_cents'], $cell['hours_minutes'])
                : null;

            $row['months'][$month] = $cell;
            $row['totals']['income_cents'] += $cell['income_cents'];
            $row['totals']['expense_cents'] += $cell['expense_cents'];
            $row['totals']['hours_minutes'] += $cell['hours_minutes'];
        }

        $row['totals']['result_cents'] = $row['totals']['income_cents'] - $row['totals']['expense_cents'];
        $row['totals']['result_per_hour_cents'] = $row['totals']['hours_minutes'] > 0
            ? intdiv($row['totals']['result_cents'], $row['totals']['hours_minutes'])
            : null;
        $row['months'] = array_values($row['months']);

        return $row;
    }

    /** @return array<int, array<string, int>> */
    private static function emptyMonthTotals(): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'income_cents' => 0,
                'expense_cents' => 0,
                'hours_minutes' => 0,
                'result_cents' => 0,
            ];
        }

        return $months;
    }

    /** @return array<string, int|null> */
    private static function emptyTotals(): array
    {
        return [
            'income_cents' => 0,
            'expense_cents' => 0,
            'hours_minutes' => 0,
            'result_cents' => 0,
            'result_per_hour_cents' => null,
        ];
    }

    private static function projectName(mixed $project): string
    {
        return is_string($project) && trim($project) !== '' ? trim($project) : self::NO_PROJECT;
    }
}
