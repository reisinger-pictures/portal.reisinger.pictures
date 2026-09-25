<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Shared allow-list guard for persisted status columns.
 *
 * A status column is validated as a *transition*: only an attribute that is
 * actually written may be rejected. Rows that were persisted with a legacy
 * value (before the current enum/allow-list existed, or by an import) must not
 * turn every unrelated save into a 500 — otherwise a single unknown value
 * blocks payment-failure bookkeeping, invoice archiving or board notes.
 *
 * Invariants:
 * - an untouched status is never validated, so legacy values survive unrelated
 *   saves unchanged;
 * - writing a value outside the allow-list (including `null`) is rejected with
 *   the same `InvalidArgumentException` as before, so no new state can be
 *   created through the models.
 */
final class ModelStatusGuard
{
    /**
     * Reject a status write that would introduce a value outside the allow-list.
     *
     * @param  array<int, string>  $allowed
     */
    public static function assertTransitionAllowed(
        Model $model,
        string $attribute,
        array $allowed,
        string $label,
    ): void {
        if (! $model->isDirty($attribute)) {
            return;
        }

        $value = $model->getAttribute($attribute);

        if (is_string($value) && in_array($value, $allowed, true)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf('Ungültiger %s: %s', $label, self::describe($value))
        );
    }

    /**
     * Whether a persisted value is part of the current allow-list.
     *
     * Used by diagnostics/tests to tell a legacy row from a valid one; it is
     * deliberately read-only and never mutates the model.
     *
     * @param  array<int, string>  $allowed
     */
    public static function isKnown(mixed $value, array $allowed): bool
    {
        return is_string($value) && in_array($value, $allowed, true);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
