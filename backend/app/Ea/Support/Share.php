<?php

declare(strict_types=1);

namespace App\Ea\Support;

use App\Ea\Parity\Money;
use InvalidArgumentException;

/**
 * EAR · Betriebsanteil anwenden.
 *
 * Der Anteil ist eine ganze Prozentzahl in 5-%-Schritten (0–100). Die
 * Multiplikation bleibt ganzzahlig; gerundet wird einmal kaufmännisch auf
 * Cent, damit es keine zweite Rundungslogik neben Money gibt.
 */
final class Share
{
    public static function apply(int $cents, int $percent): int
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException(sprintf('Betriebsanteil außerhalb 0–100 %%: %d.', $percent));
        }

        return Money::divideCents($cents * $percent, 100);
    }
}
