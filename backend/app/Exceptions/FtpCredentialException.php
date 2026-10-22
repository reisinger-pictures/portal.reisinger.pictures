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
 *
 * The fifth is `invalid_current_password` (D-51): the photographer confirmed a
 * change with a current camera password that SFTPGo rejected. The service
 * answered the question — the password is simply not the right one — so the
 * answer is a portal-side precondition and not an `SftpGoException`, even
 * though the verdict comes from the service.
 */
class FtpCredentialException extends RuntimeException
{
    public const REASON_GENERATION_FAILED = 'generation_failed';

    public const REASON_MISSING_ACCOUNT_NAME = 'missing_account_name';

    public const REASON_UNUSABLE_ACCOUNT_NAME = 'unusable_account_name';

    public const REASON_UNUSABLE_INBOX_PATH = 'unusable_inbox_path';

    public const REASON_UNCREATABLE_INBOX_DIRECTORY = 'uncreatable_inbox_directory';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_NOT_RESETTABLE = 'not_resettable';

    /**
     * The photographer entered a current camera password that the service
     * rejected (D-51).
     *
     * A separate reason because it is the only one of these that is decided
     * by the *input* and not by the state of the account: a wrong password is
     * corrected by typing a different one, while a revoked account is not
     * corrected by anything the photographer can do.
     */
    public const REASON_INVALID_CURRENT_PASSWORD = 'invalid_current_password';

    public const REASON_ROTATION_IN_PROGRESS = 'rotation_in_progress';

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
     * The inbox directory could not be created, so the slug is not stored (D-2).
     *
     * Path and operating-system reason are both in the message: this failure is
     * almost always an operator problem (a missing bind mount, a read-only
     * filesystem, wrong permissions on `ftp/`), and a message without the
     * concrete path cannot be acted on.
     *
     * That operator problem is also what fixes the status code, and it is fixed
     * for **both** callers: the reset endpoint (`FtpCredentialController::
     * fromCredentialException()`) and the slug write path (`AuthController::
     * updateProfile()`) answer 503. The same holds for
     * {@see FtpCredentialException::unusableInboxPath()}, a configuration fault
     * on the same host. The two paths used to answer 500 and 422 for one and the
     * same cause, which told a client that a host fault was a field error on one
     * door and a server error on the other.
     */
    public static function couldNotCreateInboxDirectory(string $path, string $reason): self
    {
        return new self(
            self::REASON_UNCREATABLE_INBOX_DIRECTORY,
            "Das FTP-Verzeichnis '{$path}' konnte nicht angelegt werden: {$reason}. "
            .'Der FTP-Login wurde nicht gespeichert. Bitte den Host-Pfad und seine Schreibberechtigung prüfen.',
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
     * The three known states are spelled out as cases rather than as constants:
     * this class reports on the service from the outside and does not depend on
     * `FtpCredentialService`, so the default arm covers `error` and every state a
     * future migration may add.
     *
     * `pending` has its own text because the default arm would be a lie about
     * it: `pending` means "never provisioned", not "in an error state". The
     * photographer has a way out here — the account is created through the
     * access-data request — and the message has to name it, because the two
     * states need opposite actions. `pending` is refused only where a credential
     * is to be *changed* (D-51): a never-provisioned account has no current
     * password that could be confirmed.
     */
    public static function notResettable(string $status): self
    {
        $message = match ($status) {
            'revoked' => 'Der FTP-Kamera-Zugang dieses Kontos wurde entzogen und kann nicht zurückgesetzt werden. Bitte wende dich an die Administration, wenn du den Zugang wieder benötigst.',
            'pending' => 'Für dieses Konto wurde noch kein FTP-Kamera-Zugang eingerichtet. Bitte fordere zuerst die Zugangsdaten an.',
            default => 'Der FTP-Kamera-Zugang dieses Kontos ist in einem fehlerhaften Zustand und kann nicht zurückgesetzt werden. Bitte wende dich an die Administration.',
        };

        return new self(self::REASON_NOT_RESETTABLE, $message);
    }

    /**
     * The current camera password was rejected by SFTPGo (D-51).
     *
     * The message names the *camera* password on purpose: the photographer is
     * looking at a portal that also asks for a portal password, and the two
     * are easy to mix up. It also promises what a retry can achieve — a
     * different, correct password — because that is the one thing the
     * photographer can do about it.
     *
     * The value never appears in the message, in the reason or in the log: the
     * factory receives no password at all, so there is no path by which it
     * could reach any of them.
     */
    public static function invalidCurrentPassword(): self
    {
        return new self(
            self::REASON_INVALID_CURRENT_PASSWORD,
            'Das aktuelle Kamera-Passwort ist nicht korrekt. Bitte prüfe es und versuche es erneut.',
        );
    }

    /**
     * Another password operation for this account still holds the lock.
     */
    public static function rotationInProgress(): self
    {
        return new self(
            self::REASON_ROTATION_IN_PROGRESS,
            'Für dieses FTP-Konto wird gerade ein Kamera-Passwort geändert oder eingerichtet. Bitte versuche es erneut.',
        );
    }
}
