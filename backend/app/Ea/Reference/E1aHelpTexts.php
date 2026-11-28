<?php

declare(strict_types=1);

namespace App\Ea\Reference;

use App\Ea\Data\HelpTextsData;

/**
 * EAR · Ausfüllhilfe je Kennzahl.
 *
 * Einzige Quelle sind die committeten Zeilen in `App\Ea\Data\HelpTextsData`
 * (generiert vom Generator `php artisan ea:export-data`,
 * Abschnitt 3, Issue #74). Die Tabelle ist reine Ausfüllhilfe, keine zweite
 * Regelquelle: die Spalte „Zelle" wird absichtlich nicht ausgeliefert, es wird
 * keine Berechnung aus den Texten abgeleitet und der Text wird nicht verändert.
 */
final class E1aHelpTexts
{
    /**
     * @return list<array{kz: string, bezeichnung: string, hilfetext: string}>
     */
    public static function all(): array
    {
        return HelpTextsData::ROWS;
    }

    /**
     * @return array{kz: string, bezeichnung: string, hilfetext: string}|null
     */
    public static function forCode(string $code): ?array
    {
        foreach (self::all() as $row) {
            if ($row['kz'] === $code) {
                return $row;
            }
        }

        return null;
    }
}
