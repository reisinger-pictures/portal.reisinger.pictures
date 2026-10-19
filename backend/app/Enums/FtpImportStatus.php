<?php

namespace App\Enums;

/**
 * Outcome of one FTP inbox import run (D-35/D-36/D-37, AGENTS.md §14).
 *
 * The import path is shared by two callers with two different needs: the HTTP
 * endpoint (`FtpController::process()`) turns this into a status code and a
 * German message, and the background chain (`ImportFtpBatch`) turns it into
 * "continue the chain" or "stop and log". A string status in an array would let
 * both callers miss a case silently; as an enum, `match` is exhaustive and a new
 * case becomes a compile-time error in every mapper.
 *
 * The distinction that matters most for the chain is `Imported`/`Empty` versus
 * everything else: a rejected run imported nothing, so re-enqueueing it would
 * select the very same files again.
 */
enum FtpImportStatus: string
{
    /** At least one photo was written to the target gallery. */
    case Imported = 'imported';

    /** Nothing to do: no inbox directory, or no importable file in it. */
    case Empty = 'empty';

    /** The account lost the photographer role. */
    case NotPhotographer = 'not_photographer';

    /** No target gallery selected — D-36's boundary: files stay where they are. */
    case NoTarget = 'no_target';

    /** The selected gallery is outside the account's brand. */
    case TargetNotAllowed = 'target_not_allowed';

    /** The stored `current_ftp_gallery_id` points at a deleted gallery. */
    case GalleryMissing = 'gallery_missing';

    /** The inbox directory exists but cannot be opened (host/permission fault). */
    case UnreadableInbox = 'unreadable_inbox';

    /** A file exceeds the per-file or the whole-run byte budget (HTTP 413). */
    case LimitExceeded = 'limit_exceeded';

    /** A source changed while it was read — the camera is presumably still writing (HTTP 422). */
    case SourceChanged = 'source_changed';

    /** A staged file is not a decodable, supported image (HTTP 422). */
    case SourceUnprocessable = 'source_unprocessable';

    /** Another run of this account holds the import lock (HTTP 409 + Retry-After). */
    case Locked = 'locked';
}
