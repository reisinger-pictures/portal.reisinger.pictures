<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Portal #59/D-90: eine Belegaufteilung oder Gruppenzuordnung verletzt die
 * Buchhaltungsregeln (Überallokation, doppelter Split, gebuchtes Jahr).
 * Der API-Layer bildet diese Ausnahme auf HTTP 422 ab.
 */
class EurDocumentPositionException extends RuntimeException
{
    public const DOUBLE_SPLIT_MESSAGE = 'Dieser Beleg wurde bereits aufgeteilt. Eine zweite Aufteilung ist nicht zulässig.';

    public const OVER_ALLOCATION_MESSAGE = 'Die Summe der Positionen darf den Belegbetrag nicht überschreiten.';

    public const BOOKED_MESSAGE = 'Ein Anlagegut mit gebuchtem Jahr kann keiner Gruppe zugeordnet werden.';
}
