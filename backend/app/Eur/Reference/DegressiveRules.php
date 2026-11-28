<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use App\Eur\Data\DegressiveRulesData;
use RuntimeException;

/**
 * EAR · DEG-007: zulässige Ausnahmen für die degressive AfA bei immateriellen
 * Wirtschaftsgütern.
 *
 * Die Liste steht als Programmcode in `App\Eur\Data\DegressiveRulesData`
 * (generiert vom Generator
 * `php artisan eur:export-data`,
 * Issue #74). Kein Laufzeit-Lesen, keine zweite Liste im Code.
 */
final class DegressiveRules
{
    private const RULE_ID = 'DEG-007';

    private const PARAMETER = 'Zulässige Ausnahmen';

    /**
     * @return list<string> `digitalisation`, `eco`, `health` — in der
     *                      Reihenfolge des gepinnten Artefakts
     */
    public function allowedImmaterialExceptions(): array
    {
        $parts = DegressiveRulesData::ALLOWED_IMMATERIAL_EXCEPTIONS;

        if ($parts === []) {
            throw new RuntimeException(sprintf('Regel %s: Parameter "%s" ist leer.', self::RULE_ID, self::PARAMETER));
        }

        return $parts;
    }

    public function isAllowedImmaterialException(?string $exception): bool
    {
        return $exception !== null && in_array($exception, $this->allowedImmaterialExceptions(), true);
    }
}
