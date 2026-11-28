<?php

declare(strict_types=1);

namespace App\Eur\Data;

/**
 * GENERIERT von `php artisan eur:export-data` aus dem gepinnten
 * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.
 *
 * Regel DEG-007: zulässige Ausnahmen für die degressive AfA bei
 * immateriellen Wirtschaftsgütern, in der Reihenfolge des Artefakts.
 */
final class DegressiveRulesData
{
    /**
     * @var list<string>
     */
    public const ALLOWED_IMMATERIAL_EXCEPTIONS = ['digitalisation', 'eco', 'health'];
}
