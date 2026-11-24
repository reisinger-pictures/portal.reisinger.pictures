<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Domain\JournalRules;
use Illuminate\Support\Collection;

/**
 * EAR · Erinnerung an die reale SV-Buchung (Portal #58).
 *
 * Das Portal berechnet die Sozialversicherung und zieht bereits gebuchte
 * KZ-9225-Beträge in der Berechnung heran (EUE-109/115), prüft aber nicht, ob
 * die quartalsweise Zahlung tatsächlich gebucht wurde. Diese Klasse liefert
 * eine **nicht blockierende** Erinnerung: 3800 ct je Quartal (vom Eigentümer
 * genannt) gegen die Summe der gebuchten 9225-Einträge des Quartals.
 *
 * Bewusst KEINE Herleitung aus `svMonthly()`: gepinnte Werte (u. a. das
 * FSVG-Minimum nach D-79) bleiben unangetastet, und die Erinnerung ist keine
 * Buchungsaufforderung für den bereits berechneten Betrag (keine Doppelzählung).
 */
final class EurSvBookingReminder
{
    public const QUARTER_TARGET_CENTS = 3800;

    public const SV_CODE = '9225';

    public const QUARTER_END_MONTHS = [3, 6, 9, 12];

    /**
     * @param  Collection<int, mixed>  $entries  Buchungen des Jahres (brand-scoped)
     * @return array{
     *     target_per_quarter_cents: int,
     *     quarters: list<array{quarter: int, booked_cents: int, done: bool}>,
     *     open_quarters: list<int>,
     *     message: string|null
     * }
     */
    public function forYear(int $year, Collection $entries): array
    {
        $quarters = [];

        foreach ([1, 2, 3, 4] as $quarter) {
            $booked = $this->bookedForQuarter($entries, $quarter);
            $quarters[] = [
                'quarter' => $quarter,
                'booked_cents' => $booked,
                'done' => $booked >= self::QUARTER_TARGET_CENTS,
            ];
        }

        $open = array_values(array_map(
            static fn (array $row): int => $row['quarter'],
            array_filter($quarters, static fn (array $row): bool => ! $row['done']),
        ));

        return [
            'target_per_quarter_cents' => self::QUARTER_TARGET_CENTS,
            'quarters' => $quarters,
            'open_quarters' => $open,
            'message' => $open === [] ? null : sprintf(
                '⚠ Sozialversicherung: noch keine ausreichende 9225-Buchung für Q%s %d (38,00 € je Quartal). Bitte die tatsächliche Zahlung als Ausgabe mit KZ 9225 erfassen; die berechnete SV-Tabelle ersetzt keine Buchung.',
                implode(', Q', array_map('strval', $open)),
                $year,
            ),
        ];
    }

    /**
     * Prüfung für die Monatsansicht — nur in Quartalsmonaten.
     *
     * @return array{code: string, ok: bool, message: string, hint: string}|null
     */
    public function quarterEndCheck(int $year, int $month, int $quarterBookedCents): ?array
    {
        if (! in_array($month, self::QUARTER_END_MONTHS, true)) {
            return null;
        }

        $done = $quarterBookedCents >= self::QUARTER_TARGET_CENTS;

        return JournalRules::check(
            'SV-9225',
            $done,
            $done
                ? sprintf('SV-Buchung Q%d %d erfasst (%d ct).', intdiv($month - 1, 3) + 1, $year, $quarterBookedCents)
                : sprintf('SV-Buchung Q%d %d fehlt (%.2f € gebucht, 38,00 € erwartet).', intdiv($month - 1, 3) + 1, $year, $quarterBookedCents / 100),
            'Bitte die tatsächliche Quartalszahlung als Ausgabe mit KZ 9225 erfassen. Die berechnete SV-Spalte ist keine Buchung.',
        );
    }

    /**
     * @param  Collection<int, mixed>  $entries
     */
    private function bookedForQuarter(Collection $entries, int $quarter): int
    {
        $start = ($quarter - 1) * 3 + 1;
        $end = $start + 2;

        return (int) $entries
            ->filter(function (mixed $entry) use ($start, $end): bool {
                if (! is_object($entry)) {
                    return false;
                }

                $month = (int) ($entry->month ?? 0);
                $code = (string) ($entry->e1a_code ?? '');

                return $month >= $start && $month <= $end && str_starts_with($code, self::SV_CODE);
            })
            ->sum(static fn (mixed $entry): int => (int) (is_object($entry) ? ($entry->business_amount_cents ?? 0) : 0));
    }
}
