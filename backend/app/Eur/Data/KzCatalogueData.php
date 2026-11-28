<?php

declare(strict_types=1);

namespace App\Eur\Data;

/**
 * GENERIERT von `php artisan eur:export-data` aus dem gepinnten
 * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.
 *
 * E1A-Kennzahlenlisten aus dem gepinnten Kennzahlenkatalog: die Zellen
 * der Regelzeilen EUE-023 und EUE-024, exakt wie im Artefakt.
 */
final class KzCatalogueData
{
    /**
     * @var list<string>
     */
    public const EXPENSE_CODES = [
        '9100',
        '9110',
        '9120',
        '9130',
        '9134',
        '9135',
        '9150',
        '9160',
        '9165',
        '9170',
        '9180',
        '9190',
        '9200',
        '9210',
        '9220',
        '9275',
        '9225',
        '9243',
        '9244',
        '9245',
        '9246',
        '9230',
        '9500',
        '9510',
        '9520',
        '9521',
        '9530',
    ];

    /**
     * @var list<string>
     */
    public const INCOME_CODES = [
        '9040',
        '9050',
        '9060',
        '9090',
        '9460',
    ];

    /**
     * @var list<string>
     */
    public const E1B_CODES = [
        '9500',
        '9135',
        '9510',
        '9520',
        '9521',
        '9530',
    ];
}
