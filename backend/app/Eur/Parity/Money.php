<?php

declare(strict_types=1);

namespace App\Eur\Parity;

use InvalidArgumentException;

/**
 * EÜR · Parität — Umrechnungen an der Lesegrenze der Clean-Room-Basis.
 *
 * Die Fixture liefert Basiswerte als Zahlen (EUR, Stunden, Anteil). Danach
 * sind ausschließlich ganze Cent bzw. ganze Minuten erlaubt: alle Rückgaben
 * dieser Klasse sind Integer, es wird kein Geldwert als Float gespeichert
 * oder weitergereicht.
 */
final class Money
{
    public static function eurosToCents(float|int|string $euros): int
    {
        return self::scaledInt(self::decimal($euros), '100');
    }

    public static function hoursToMinutes(float|int|string $hours): int
    {
        return self::scaledInt(self::decimal($hours), '60');
    }

    public static function applyShare(int $cents, float|string $share = 1.0): int
    {
        return self::scaledInt((string) $cents, self::decimal($share));
    }

    /**
     * Explizite Dezimalschreibweise ohne Locale-Abhängigkeit und ohne
     * Exponentialnotation. Das verhindert Artefakte der Float-Repräsentation
     * (z. B. 58,8 -> 58.8 statt 58.79999...).
     */
    private static function decimal(float|int|string $value): string
    {
        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));

            if (! is_numeric($normalized)) {
                throw new InvalidArgumentException(sprintf('Kein numerischer Wert: "%s".', $value));
            }

            return $normalized;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * BCMath rechnet im Voraus exakt, erst das Ergebnis wird ganzzahlig
     * (kaufmännisch, auch bei negativen Beträgen).
     */
    private static function scaledInt(string $value, string $factor): int
    {
        $scaled = bcmul($value, $factor, 2);
        $rounded = bccomp($scaled, '0', 2) < 0
            ? bcsub($scaled, '0.5', 2)
            : bcadd($scaled, '0.5', 2);

        return (int) bcadd($rounded, '0', 0);
    }
}
