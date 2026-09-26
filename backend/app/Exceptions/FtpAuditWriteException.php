<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The audit trail for a camera password reset could not be written (P1-M33).
 *
 * Separate from `FtpCredentialException` on purpose. That one reports a
 * precondition the photographer can act on ("fix your profile", "too many
 * resets"), and the controller answers it with 422 or 429. This one reports that
 * the portal could not record what it just did — a database problem, not
 * something in the request — and the correct answer is a 500.
 *
 * It exists so the failure cannot pass silently. `Eloquent::create()` returns an
 * unsaved model when a `creating` listener vetoes the insert, and a vetoed audit
 * write that nobody checks would be an unaudited credential rotation: the
 * password the camera must use changed, and no record of it exists. The
 * exception is what turns that into a loud failure.
 */
class FtpAuditWriteException extends RuntimeException
{
    public static function forPasswordReset(): self
    {
        return new self(
            'Das FTP-Passwort wurde geändert, konnte aber nicht protokolliert werden. '
            .'Bitte den Vorgang wiederholen und den Portalbetrieb prüfen.',
        );
    }
}
