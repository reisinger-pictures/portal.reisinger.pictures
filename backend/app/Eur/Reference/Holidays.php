<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use InvalidArgumentException;

/**
 * EAR · Feiertage (EUE-012) — österreichische Feiertage je Jahr.
 *
 * Die beweglichen Feiertage werden über die Osterformel (Meeus/Jones/Butcher,
 * rein ganzzahlig) abgeleitet. Die festen Feiertage stehen als Kalenderdaten,
 * nicht als Länder- oder Betragswerte. Für das gepinnte Wirtschaftsjahr 2026
 * vergleicht der Test die Ableitung mit dem Referenzartefakt.
 */
final class Holidays
{
    /** @var list<array{0:int,1:int,2:string}> */
    private const FIXED = [
        [1, 1, 'Neujahr'],
        [1, 6, 'Heilige Drei Könige'],
        [5, 1, 'Staatsfeiertag'],
        [8, 15, 'Mariä Himmelfahrt'],
        [10, 26, 'Nationalfeiertag'],
        [11, 1, 'Allerheiligen'],
        [12, 8, 'Mariä Empfängnis'],
        [12, 25, 'Christtag'],
        [12, 26, 'Stefanitag'],
    ];

    /** @var list<array{0:int,1:string}> Ostersonntag + n Tage */
    private const MOVABLE = [
        [1, 'Ostermontag'],
        [39, 'Christi Himmelfahrt'],
        [50, 'Pfingstmontag'],
        [60, 'Fronleichnam'],
    ];

    /**
     * @return list<array{date: string, name: string}>
     */
    public static function forYear(int $year): array
    {
        if ($year < 1583) {
            throw new InvalidArgumentException(sprintf('Osterformel gilt erst ab 1583, erhalten: %d.', $year));
        }

        $easter = self::easterSunday($year);
        $holidays = [];

        foreach (self::FIXED as [$month, $day, $name]) {
            $holidays[] = [
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'name' => $name,
            ];
        }

        foreach (self::MOVABLE as [$offset, $name]) {
            $holidays[] = [
                'date' => $easter->modify(sprintf('+%d days', $offset))->format('Y-m-d'),
                'name' => $name,
            ];
        }

        usort($holidays, static fn (array $left, array $right): int => $left['date'] <=> $right['date']);

        return $holidays;
    }

    /**
     * @return list<string> ISO-Datumsangaben, aufsteigend
     */
    public static function datesForYear(int $year): array
    {
        return array_map(static fn (array $holiday): string => $holiday['date'], self::forYear($year));
    }

    public static function easterSunday(int $year): \DateTimeImmutable
    {
        if ($year < 1583) {
            throw new InvalidArgumentException(sprintf('Osterformel gilt erst ab 1583, erhalten: %d.', $year));
        }

        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
