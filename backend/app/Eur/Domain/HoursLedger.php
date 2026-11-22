<?php

declare(strict_types=1);

namespace App\Eur\Domain;

use InvalidArgumentException;

/**
 * EAR · Stundenbuch (EUE-080, EUE-123).
 *
 * Reine Integer-Minuten-Aggregation: Gesamtsumme, Projekt- und Monatssummen
 * sowie die 3-Stunden-Regel (ab 180 Minuten). Keine Datenbank, keine Uhr,
 * kein Float. Ein Eintrag hat `hours_minutes` (oder `minutes`), optional
 * `project`, `month` und `voucher`.
 */
final class HoursLedger
{
    public const THREE_HOURS_MINUTES = 180;

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function sumMinutes(array $entries, ?string $project = null, ?string $month = null): int
    {
        $total = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Stundeneintrag muss ein Array sein.');
            }

            $minutes = self::minutesOf($entry);

            if ($project !== null && (string) ($entry['project'] ?? '') !== $project) {
                continue;
            }

            if ($month !== null && (string) ($entry['month'] ?? '') !== $month) {
                continue;
            }

            $total += $minutes;
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    public static function byProject(array $entries): array
    {
        return self::groupBy($entries, 'project');
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    public static function byMonth(array $entries): array
    {
        return self::groupBy($entries, 'month');
    }

    /**
     * EUE-080/EUE-071: die 3-Stunden-Regel als Zeilenprüfung.
     *
     * @return array{code: string, ok: bool, message: string, hint: string, flag: bool}
     */
    public static function threeHourCheck(int $minutes): array
    {
        $reached = $minutes >= self::THREE_HOURS_MINUTES;
        $check = JournalRules::check(
            '3H',
            ! $reached,
            $reached
                ? sprintf('Zeitbuchung von %d Minuten erreicht die 3-Stunden-Grenze.', $minutes)
                : sprintf('Zeitbuchung von %d Minuten liegt unter der 3-Stunden-Grenze.', $minutes),
            $reached
                ? 'Bitte prüfen, ob die Stunden als Arbeitszeit oder als Reisezeit der Hin-/Rückfahrt zu erfassen sind.'
                : 'Keine weitere Prüfung nötig; die 3-Stunden-Grenze ist nicht erreicht.',
        );

        return $check + ['flag' => $reached];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     total_minutes: int,
     *     by_project: array<string, int>,
     *     by_month: array<string, int>,
     *     checks: list<array<string, mixed>>,
     *     over_three_hours: bool
     * }
     */
    public static function summarize(array $entries): array
    {
        $byProject = self::byProject($entries);
        $byMonth = self::byMonth($entries);
        $total = self::sumMinutes($entries);
        $checks = [];

        foreach ($byProject as $project => $minutes) {
            $checks[] = self::threeHourCheck($minutes) + ['project' => (string) $project];
        }

        $global = self::threeHourCheck($total);

        return [
            'total_minutes' => $total,
            'by_project' => $byProject,
            'by_month' => $byMonth,
            'checks' => $checks,
            'over_three_hours' => $global['flag'],
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private static function groupBy(array $entries, string $key): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Stundeneintrag muss ein Array sein.');
            }

            $label = (string) ($entry[$key] ?? '');
            $groups[$label] = ($groups[$label] ?? 0) + self::minutesOf($entry);
        }

        ksort($groups);

        return $groups;
    }

    /** @param array<string, mixed> $entry */
    private static function minutesOf(array $entry): int
    {
        $minutes = $entry['hours_minutes'] ?? $entry['minutes'] ?? null;

        if (! is_int($minutes)) {
            throw new InvalidArgumentException('Stundeneintrag ohne ganzzahlige Minuten.');
        }

        return $minutes;
    }
}
