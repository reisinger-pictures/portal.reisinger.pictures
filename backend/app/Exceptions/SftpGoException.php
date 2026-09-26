<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Failure of a single call against the SFTPGo Admin API (P1-M22).
 *
 * Every factory accepts the account name and the HTTP status only — there is
 * deliberately no factory that takes a camera password. A camera password can
 * therefore not reach a log line, a stack trace or an API response through this
 * exception, which is what `features/infrastructure/19-ftp-upload-pipeline.md`
 * 7.3 ("Passwort-Fluss: erzeugen, anzeigen, verwerfen") requires.
 *
 * The status mapping follows the running instance, not a guess: SFTPGo's
 * `getRespStatus` (internal/httpd/api_utils.go, 2.7.6) maps a duplicated key
 * to 409, a not-found to 404, a validation error to 400, a method-disabled or
 * permission error to 403 and everything else to 500.
 */
class SftpGoException extends RuntimeException
{
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_UNREACHABLE = 'unreachable';

    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_ALREADY_EXISTS = 'already_exists';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_INVALID_REQUEST = 'invalid_request';

    public const REASON_SERVER_ERROR = 'server_error';

    public const REASON_MALFORMED_RESPONSE = 'malformed_response';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::REASON_NOT_CONFIGURED,
            'SFTPGo ist nicht konfiguriert: weder API-Key noch Admin-Zugangsdaten gesetzt.',
        );
    }

    /**
     * The portal itself rejected the input before any request left the process
     * — an unusable account name or a relative home directory. There is no HTTP
     * status because nothing was sent.
     */
    public static function invalidRequest(string $account, string $detail): self
    {
        return new self(
            self::REASON_INVALID_REQUEST,
            "FTP-Konto '{$account}' kann nicht angelegt werden: {$detail}",
        );
    }

    /**
     * The service did not answer at all (connection refused, timeout, DNS).
     * The previous exception is the transport error; it never carries the
     * request body, so the camera password stays out of the chain.
     */
    public static function unreachable(string $account, ?Throwable $previous = null): self
    {
        return new self(
            self::REASON_UNREACHABLE,
            "SFTPGo ist nicht erreichbar (FTP-Konto '{$account}').",
            null,
            $previous,
        );
    }

    public static function fromStatus(string $account, int $status): self
    {
        return match (true) {
            $status === 401, $status === 403 => new self(
                self::REASON_UNAUTHORIZED,
                "SFTPGo hat die Anfrage abgelehnt (HTTP {$status}). API-Key bzw. Admin-Zugang prüfen.",
                $status,
            ),
            $status === 404 => new self(
                self::REASON_NOT_FOUND,
                "FTP-Konto '{$account}' existiert im Dienst nicht (HTTP 404).",
                $status,
            ),
            $status === 409 => new self(
                self::REASON_ALREADY_EXISTS,
                "FTP-Konto '{$account}' existiert im Dienst bereits (HTTP 409).",
                $status,
            ),
            $status >= 400 && $status < 500 => new self(
                self::REASON_INVALID_REQUEST,
                "SFTPGo hat die Anfrage für FTP-Konto '{$account}' abgelehnt (HTTP {$status}).",
                $status,
            ),
            default => new self(
                self::REASON_SERVER_ERROR,
                "SFTPGo hat einen Serverfehler gemeldet (HTTP {$status}).",
                $status,
            ),
        };
    }

    /**
     * A 2xx whose body is not the documented JSON object. Treated as a failure
     * instead of being passed on, so no caller has to defend against a
     * non-array "successful" response.
     */
    public static function malformedResponse(string $account): self
    {
        return new self(
            self::REASON_MALFORMED_RESPONSE,
            "SFTPGo hat fuer FTP-Konto '{$account}' eine unerwartete Antwort geliefert.",
            null,
        );
    }
}
