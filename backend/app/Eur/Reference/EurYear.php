<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use InvalidArgumentException;

/**
 * EAR · Wirtschaftsjahr — Regeljahre und Buchungsjahre (EUE-001 … EUE-003).
 *
 * Regeljahre und Buchungsjahre sind nicht deckungsgleich (Owner-Entscheidungen
 * #75/#78):
 *
 * - **Regeljahre** sind die Jahre mit eingepflegter, gepinnter Regelbasis
 *   (Tarif, SV-Tabellen, Kilometergeld, AfA). 2024 und 2025 bleiben erhalten,
 *   weil die Vorjahresvergleiche EUE-056/057 und die AfA sie brauchen.
 * - **Buchungsjahre** sind die Jahre, in denen gebucht werden darf. Sie
 *   beginnen mit {@see self::BOOKING_FROM}; 2024 und 2025 werden von keinem
 *   Schreibpfad mehr angeboten oder akzeptiert.
 *
 * Fehlt für ein Buchungsjahr die gepinnte Basis (heute 2027), rechnet das
 * Portal mit den Regeln des letzten Regeljahres weiter: {@see self::effective()}
 * klemmt die Eingabe auf `[erstes Regeljahr, LAST_PINNED]`, statt den Request
 * abzubrechen.
 */
final class EurYear
{
    /** @var list<int> Regeljahre mit eingepflegter Basis */
    public const PINNED = [2024, 2025, 2026];

    /** Letztes Regeljahr — Fallback für alle späteren Buchungsjahre. */
    public const LAST_PINNED = 2026;

    /** Erstes buchbares Jahr. */
    public const BOOKING_FROM = 2026;

    /**
     * Nur für konstante Default-Argumente gedacht. Request-Fallbacks und
     * Jahresvergleiche nutzen {@see self::currentOrLast()} bzw.
     * {@see self::LAST_PINNED}.
     */
    public const DEFAULT = 2026;

    /** @return list<int> */
    public static function pinned(): array
    {
        return self::PINNED;
    }

    /**
     * Buchbare Jahre innerhalb der gepinnten Basis. Spätere Jahre sind über
     * {@see self::accepts()} ebenfalls buchbar und rechnen auf dem Fallback.
     *
     * @return list<int>
     */
    public static function bookable(): array
    {
        return array_values(array_filter(
            self::PINNED,
            static fn (int $year): bool => $year >= self::BOOKING_FROM,
        ));
    }

    public static function isPinned(int $year): bool
    {
        return in_array($year, self::PINNED, true);
    }

    /** Buchungsjahre sind nach oben offen: jedes Jahr ab {@see self::BOOKING_FROM}. */
    public static function accepts(int $year): bool
    {
        return $year >= self::BOOKING_FROM;
    }

    /** Eingabeprüfung der Schreibpfade: Regeljahre sind keine Buchungsjahre. */
    public static function assertBookable(int $year): void
    {
        if (! self::accepts($year)) {
            throw new InvalidArgumentException(sprintf(
                'Buchungsjahre ab %d; %d wird nicht als Buchungsjahr akzeptiert.',
                self::BOOKING_FROM,
                $year,
            ));
        }
    }

    /**
     * Eingabeprüfung der Regel-Lookups: 2024 und 2025 bleiben zulässig, weil
     * Vorjahresvergleiche und AfA sie brauchen. Erst unterhalb des ältesten
     * Regeljahres gibt es keine Basis mehr.
     */
    public static function assert(int $year): void
    {
        if ($year < self::PINNED[0]) {
            throw new InvalidArgumentException(sprintf(
                'Wirtschaftsjahr %d wird nicht unterstützt; Regeljahre sind ab %d eingepflegt.',
                $year,
                self::PINNED[0],
            ));
        }
    }

    /**
     * Regelbasis für ein Buchungsjahr: das letzte Regeljahr, solange die
     * Basis für das Jahr fehlt.
     *
     * 2027 wird zu 2026, 2024 bleibt 2024; frühere Jahre werden auf das
     * älteste Regeljahr geklemmt.
     */
    public static function effective(int $year): int
    {
        return max(self::PINNED[0], min($year, self::LAST_PINNED));
    }

    /**
     * Laufendes Jahr, wenn es ein Buchungsjahr ist, sonst das letzte Regeljahr.
     *
     * Der Default aller Request-Fallbacks. `now()` statt `date('Y')`, damit
     * `travelTo()` in Tests wirkt.
     */
    public static function currentOrLast(): int
    {
        $year = (int) now()->year;

        return self::accepts($year) ? $year : self::LAST_PINNED;
    }
}
