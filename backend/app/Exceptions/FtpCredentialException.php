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
 *
 * One more portal-side precondition lives here as of P1-M33: `rate_limited`.
 * The reset is the only recovery path for a lost camera password, which makes it
 * an unlimited mint for valid credentials unless the portal caps it. That cap
 * is enforced before any request leaves the process, so it belongs with the
 * other "the portal stopped this" reasons and not with `SftpGoException`.
 */
class FtpCredentialException extends RuntimeException
{
    public const REASON_GENERATION_FAILED = 'generation_failed';

    public const REASON_MISSING_ACCOUNT_NAME = 'missing_account_name';

    public const REASON_UNUSABLE_ACCOUNT_NAME = 'unusable_account_name';

    public const REASON_UNUSABLE_INBOX_PATH = 'unusable_inbox_path';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $reason,
        string $message,
        /**
         * Seconds until the quota frees up, for `rate_limited` only. It is
         * data the client needs to back off correctly, so it is part of the
         * exception instead of something the controller has to recompute from a
         * limiter key it no longer has. Null for every other reason.
         */
        public readonly ?int $retryAfterSeconds = null,
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

    /**
     * The per-account reset quota is exhausted (P1-M33). The limit is named in
     * the message instead of being hardcoded into a string, so the text cannot
     * drift away from the constant that enforces it.
     */
    public static function rateLimited(int $limit, int $retryAfterSeconds): self
    {
        return new self(
            self::REASON_RATE_LIMITED,
            "Zu viele FTP-Passwort-Zurücksetzungen für dieses Konto (maximal {$limit} pro Stunde). Bitte später erneut versuchen.",
            max(1, $retryAfterSeconds),
        );
    }
}
