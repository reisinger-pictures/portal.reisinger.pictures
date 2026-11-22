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

    /**
     * Anteil 0.0–1.0 → ganze Prozent 0–100 (z. B. 0.6 → 60).
     */
    public static function fractionToPercent(float|int|string $fraction): int
    {
        return self::scaledInt(self::decimal($fraction), '100');
    }

    /**
     * Anteil 0.0–1.0 → Basispunkte (1/10000), z. B. 0.068 → 680.
     */
    public static function fractionToPer10000(float|int|string $fraction): int
    {
        return self::scaledInt(self::decimal($fraction), '10000');
    }

    /**
     * Rundet einen bereits in Cent ausgedrückten Dezimalwert kaufmännisch.
     */
    public static function roundCents(float|int|string $cents): int
    {
        return self::scaledInt(self::decimal($cents), '1');
    }

    /**
     * Teilt einen Cent-Betrag und rundet kaufmännisch auf ganze Cent.
     */
    public static function divideCents(int $cents, float|int|string $divisor): int
    {
        $decimal = self::decimal($divisor);

        if (bccomp($decimal, '0', 8) === 0) {
            throw new InvalidArgumentException('Division durch null.');
        }

        return self::roundCents(bcdiv((string) $cents, $decimal, 8));
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
