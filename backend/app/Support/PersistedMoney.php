<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Shared limits and formatting for integer money columns.
 *
 * Contract line arithmetic intentionally uses the larger JavaScript safe
 * integer range, but orders and invoice snapshots are persisted in signed
 * 32-bit integer columns. Keeping the persisted ceiling here prevents each
 * writer from silently relying on a different database assumption.
 */
final class PersistedMoney
{
    /** The maximum value supported by the existing signed INT money columns. */
    public const MAX_CENTS = 2_147_483_647;

    /**
     * Validate a value before it is written to a persisted money column.
     * Non-negative business totals are expected by the callers; the guard is
     * specifically responsible for the shared upper bound and integral shape.
     */
    public static function assertFitsCents(mixed $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if (is_int($value)) {
            if ($value > self::MAX_CENTS) {
                self::throwCeilingExceeded($field);
            }

            return;
        }

        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value || $value > (float) self::MAX_CENTS) {
                self::throwInvalid($field);
            }

            return;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            $digits = $digits === '' ? '0' : $digits;
            $maximum = (string) self::MAX_CENTS;

            if (! $negative && (strlen($digits) > strlen($maximum)
                || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0))
            ) {
                self::throwCeilingExceeded($field);
            }

            return;
        }

        self::throwInvalid($field);
    }

    /**
     * Format integer cents without converting through a floating-point value.
     * The German PDF views use comma decimals and dot thousands separators.
     */
    public static function formatCents(
        int $value,
        string $decimalSeparator = ',',
        string $thousandsSeparator = '.',
    ): string {
        $negative = $value < 0;
        $absolute = $negative ? -$value : $value;
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;
        $wholeDigits = (string) $whole;
        $groupedWhole = preg_replace(
            '/\B(?=([0-9]{3})+(?![0-9]))/',
            $thousandsSeparator,
            $wholeDigits,
        );

        if (! is_string($groupedWhole)) {
            throw new InvalidArgumentException('The money value could not be formatted.');
        }

        $formattedFraction = str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$groupedWhole.$decimalSeparator.$formattedFraction;
    }

    private static function throwCeilingExceeded(string $field): never
    {
        throw new InvalidArgumentException("{$field} exceeds the persisted money ceiling.");
    }

    private static function throwInvalid(string $field): never
    {
        throw new InvalidArgumentException("{$field} must be an integer number of cents.");
    }
}
