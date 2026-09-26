<?php

namespace App\Exceptions;

use App\Support\FtpSlug;
use RuntimeException;

/**
 * The portal cannot hand a camera a usable FTP account (P1-M23).
 *
 * Separate from SftpGoException on purpose: that one reports what the external
 * service answered, this one reports a portal-side precondition (no account
 * name, a name that cannot be an account, an inbox root that is not absolute).
 * The caller can therefore tell "the photographer has to fix their profile"
 * apart from "the service is unavailable" — and neither carries a password,
 * because no factory here takes one.
 */
class FtpCredentialException extends RuntimeException
{
    public const REASON_GENERATION_FAILED = 'generation_failed';

    public const REASON_MISSING_ACCOUNT_NAME = 'missing_account_name';

    public const REASON_UNUSABLE_ACCOUNT_NAME = 'unusable_account_name';

    public const REASON_UNUSABLE_INBOX_PATH = 'unusable_inbox_path';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The generator produced nothing that satisfied the camera rule in the
     * allowed number of attempts. Practically unreachable; if it ever fires,
     * the source of randomness is broken and that is worth a loud failure.
     */
    public static function generationFailed(): self
    {
        return new self(
            self::REASON_GENERATION_FAILED,
            'Es konnte kein kamerataugliches FTP-Passwort erzeugt werden.',
        );
    }

    public static function missingAccountName(): self
    {
        return new self(
            self::REASON_MISSING_ACCOUNT_NAME,
            'Für dieses Konto ist kein FTP-Kontoname hinterlegt. Bitte im Profil unter "FTP-Kontoname" einen Namen vergeben.',
        );
    }

    public static function unusableAccountName(string $slug): self
    {
        return new self(
            self::REASON_UNUSABLE_ACCOUNT_NAME,
            "Der FTP-Kontoname '{$slug}' ist kein gültiger Kontoname. Bitte im Profil ändern. ".FtpSlug::message(),
        );
    }

    public static function unusableInboxPath(): self
    {
        return new self(
            self::REASON_UNUSABLE_INBOX_PATH,
            'Das FTP-Inbox-Verzeichnis (FTP_STORAGE_PATH) ist nicht als absoluter Pfad konfiguriert.',
        );
    }
}
