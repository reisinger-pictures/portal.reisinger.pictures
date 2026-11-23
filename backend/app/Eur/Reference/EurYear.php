<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use InvalidArgumentException;

/**
 * EAR · Wirtschaftsjahr (EUE-001 … EUE-003).
 *
 * Einziger Gültigkeitsbereich sind 2024–2026; 2026 ist der Default. Ein
 * unbekanntes Jahr wird abgewiesen, nie still auf einen Default abgebildet.
 */
final class EurYear
{
    public const DEFAULT = 2026;

    public const LOWER = 2024;

    public const UPPER = 2026;

    /** @var list<int> */
    public const SUPPORTED = [2024, 2025, 2026];

    public static function supports(int $year): bool
    {
        return in_array($year, self::SUPPORTED, true);
    }

    public static function assert(int $year): void
    {
        if (! self::supports($year)) {
            throw new InvalidArgumentException(sprintf(
                'Wirtschaftsjahr %d wird nicht unterstützt; zulässig sind 2024–2026.',
                $year,
            ));
        }
    }

    /**
     * EUE-002/003: Warntext außerhalb des unterstützten Bereichs.
     *
     * @return array{year: int, warning: string, code: string|null, supported: bool}
     */
    public static function warning(int $year): array
    {
        if (self::supports($year)) {
            return ['year' => $year, 'warning' => '', 'code' => null, 'supported' => true];
        }

        if ($year > self::UPPER) {
            return [
                'year' => $year,
                'warning' => '⚠ Die Tabelle nicht offiziell mit '.$year.' kompatibel. Bitte auf die neueste Version der Tabelle umsteigen.',
                'code' => 'EUE-002',
                'supported' => false,
            ];
        }

        return [
            'year' => $year,
            'warning' => '⚠ Die Tabelle nicht offiziell mit '.$year.' kompatibel. Kontaktieren Sie uns für den Zugang zu einer älteren Version (Kauf der Lifetime-Lizenz erforderlich).',
            'code' => 'EUE-003',
            'supported' => false,
        ];
    }
}
