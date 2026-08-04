<?php

namespace App\Enums;

/**
 * Production workflow statuses for the photo editing kanban board.
 * One enum case = one board column.
 */
enum PhotoJobStatus: string
{
    case IMPORTIERT = 'importiert';
    case CULLING = 'culling';
    case BEARBEITUNG = 'bearbeitung';
    case EXPORTIERT = 'exportiert';
    case ABGEBROCHEN = 'abgebrochen';

    /** @return self */
    public static function initial(): self
    {
        return self::IMPORTIERT;
    }
}