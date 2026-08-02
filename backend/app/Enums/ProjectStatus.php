<?php

namespace App\Enums;

/**
 * Commercial workflow statuses for the projects kanban board.
 * One enum case = one board column.
 */
enum ProjectStatus: string
{
    case ANFRAGE = 'anfrage';
    case ANGEBOT = 'angebot';
    case BEAUFTRAGT = 'beauftragt';
    case RECHNUNG = 'rechnung';
    case BEZAHLT = 'bezahlt';
    case STORNIERT = 'storniert';

    /** The status a new project is created in. */
    public static function initial(): self
    {
        return self::ANFRAGE;
    }
}