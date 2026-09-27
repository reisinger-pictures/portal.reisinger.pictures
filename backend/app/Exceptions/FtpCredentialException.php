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
 * The reset is the only recovery path for a lost camera password, but it does
 * not mint credentials: it **replaces** the password, so the quota bounds how
 * often one account can invalidate its own working camera and how much
 * read-modify-write load it puts on SFTPGo — not how many credentials exist. The
 * cap is enforced before any request leaves the process, so it belongs with the
 * other "the portal stopped this" reasons and not with `SftpGoException`.
 *
 * The fourth portal-side precondition is `not_resettable` (P1-M58): the account
 * state says the access may not be re-issued at all — `revoked` after a role
 * loss, or `error` from a provisioning attempt whose outcome is unknown. It is
 * not a failure of the input and not a failure of the service, it is a state the
 * portal refuses to touch, so it cannot be an `SftpGoException` and it never
 * reaches SFTPGo either.
 */
class FtpCredentialException extends RuntimeException
{
    public const REASON_GENERATION_FAILED = 'generation_failed';

    public const REASON_MISSING_ACCOUNT_NAME = 'missing_account_name';

    public const REASON_UNUSABLE_ACCOUNT_NAME = 'unusable_account_name';

    public const REASON_UNUSABLE_INBOX_PATH = 'unusable_inbox_path';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_NOT_RESETTABLE = 'not_resettable';

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

    /**
     * The account state forbids re-issuing a credential (P1-M58), and the reason
     * is passed in so the photographer is told *why* instead of only that it did
     * not work.
     *
     * Both texts have to make one thing unmistakable: retrying changes nothing.
     * They name the administration as the way out, because neither state can be
     * cleared from the photographer's own screen — a `revoked` account was taken
     * away deliberately, and an `error` one has an unknown outcome that only a
     * look at the service can resolve.
     *
     * The two known states are spelled out as cases rather than as constants:
     * this class reports on the service from the outside and does not depend on
     * `FtpCredentialService`, so the default arm covers `error` and every state a
     * future migration may add.
     */
    public static function notResettable(string $status): self
    {
        $message = match ($status) {
            'revoked' => 'Der FTP-Kamera-Zugang dieses Kontos wurde entzogen und kann nicht zurückgesetzt werden. Bitte wende dich an die Administration, wenn du den Zugang wieder benötigst.',
            default => 'Der FTP-Kamera-Zugang dieses Kontos ist in einem fehlerhaften Zustand und kann nicht zurückgesetzt werden. Bitte wende dich an die Administration.',
        };

        return new self(self::REASON_NOT_RESETTABLE, $message);
    }
}
