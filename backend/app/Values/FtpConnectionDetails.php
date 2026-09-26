<?php

namespace App\Values;

use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * The connection details a camera needs, as shown in the management UI.
 *
 * This exists as a value object rather than an array literal in the controller
 * for two reasons.
 *
 * First, the values are host-facing configuration, and every one of them can be
 * absent: `FTP_PUBLIC_HOST` and the four port variables are read with no
 * defaults (config/services.php), because a default would be an invisible guess
 * about this deployment's network. A missing value must therefore be reported as
 * "not configured" instead of being rendered as an empty field the photographer
 * would paste into a camera.
 *
 * Second, the ports are shared with the compose file, which publishes
 * `${SFTPGO_SFTP_PORT:-2222}`. Because this reads the same variable, the UI
 * cannot show a port the container does not listen on — the class of bug that
 * made the first cutover unreachable, where the compose published `2222:2222`
 * while SFTPGo listened on 2022 (feature doc 7.13).
 *
 * Nothing here is secret. The password is shown once by the credential endpoint
 * and is deliberately not part of this payload.
 */
final class FtpConnectionDetails
{
    /**
     * The subpath a camera is configured with when the deployment declares none.
     *
     * The account root is the correct default: each account has its own physical
     * directory, and one target gallery takes everything that arrives. A
     * subfolder is configured with `FTP_UPLOAD_PATH` and is what makes Canon's
     * *Ordner wählen* field usable.
     */
    public const UPLOAD_PATH = '/';

    /**
     * Rejects anything that could escape the account directory.
     *
     * The value is deployment configuration, but it reaches a filesystem path,
     * so `..` and absolute paths are refused rather than normalised away. An
     * invalid value reads as `null`, which drops the row, instead of silently
     * becoming something the photographer would configure in the camera.
     */
    private const PATH_PATTERN = '#^(/[A-Za-z0-9_-]+)+$|^/$#';

    private function __construct(
        public readonly ?string $host,
        public readonly ?string $username,
        public readonly ?int $sftpPort,
        public readonly ?int $ftpsPort,
        public readonly ?int $pasvPortStart,
        public readonly ?int $pasvPortEnd,
        public readonly ?string $ftpsTlsMode,
        public readonly ?string $uploadPath,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            self::text(Config::get('services.ftp_transport.public_host')),
            self::text($user->ftp_slug),
            self::port(Config::get('services.ftp_transport.sftp_port')),
            self::port(Config::get('services.ftp_transport.ftps_port')),
            self::port(Config::get('services.ftp_transport.pasv_port_start')),
            self::port(Config::get('services.ftp_transport.pasv_port_end')),
            self::tlsMode(Config::get('services.ftp_transport.ftps_tls_mode')),
            self::uploadPath(Config::get('services.ftp_transport.upload_path')),
        );
    }

    /**
     * A photographer can only be given a camera configuration when the host is
     * known and both ports are valid. The passive range is reported but does not
     * gate this: it is a firewall matter, not something the camera is told.
     */
    public function isConfigured(): bool
    {
        // Der Zielordner gehoert dazu. Ein malformierter Wert liest sich als
        // `path: null`, und ohne ihn in dieser Pruefung saehe die Oberflaeche
        // eine vollstaendige Verbindung ohne Zielordner — der Fotograf wuerde
        // das Feld in der Kamera raten, und ein abweichender Ordner sieht wie
        // ein leerer Posteingang aus, nicht wie ein Fehler. Der Import bleibt
        // davon unberuehrt: `resolvedUploadPath()` faellt auf die Konto-Wurzel
        // zurueck und kann dadurch nur mehr finden, nie weniger.
        return $this->host !== null && $this->username !== null
            && $this->sftpPort !== null && $this->ftpsPort !== null
            && $this->uploadPath !== null;
    }

    /**
     * @return array{
     *     configured: bool,
     *     host: ?string,
     *     username: ?string,
     *     path: ?string,
     *     sftp_port: ?int,
     *     ftps_port: ?int,
     *     pasv_port_start: ?int,
     *     pasv_port_end: ?int,
     *     ftps_tls_mode: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'host' => $this->host,
            'username' => $this->username,
            'path' => $this->uploadPath,
            'sftp_port' => $this->sftpPort,
            'ftps_port' => $this->ftpsPort,
            'pasv_port_start' => $this->pasvPortStart,
            'pasv_port_end' => $this->pasvPortEnd,
            'ftps_tls_mode' => $this->ftpsTlsMode,
        ];
    }

    /**
     * `null` for anything that is not a non-empty string. `env()` can hand back
     * a non-string for a value that only looks like one, and a `0` or `false`
     * must not become a host name.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Der Zielordner als aufgeloester Pfad, nie `null`.
     *
     * Fuer den Import: ein malformierter Wert liest sich wie "nicht
     * konfiguriert" und faellt auf das Konto-Wurzelverzeichnis zurueck. Das ist
     * die permissive Richtung — der Import kann dadurch nur mehr finden, nie
     * weniger. Die Verbindungsdaten bleiben streng und lassen die Zeile bei
     * einem malformierten Wert weg (`path: null`), damit die Kamera nicht auf
     * einen Pfad zeigt, den der Server nicht bestaetigt.
     */
    public static function resolvedUploadPath(): string
    {
        return self::uploadPath(Config::get('services.ftp_transport.upload_path')) ?? self::UPLOAD_PATH;
    }

    /**
     * The configured subfolder, or `/` when nothing is declared.
     *
     * Empty means *not declared*, which is not the same as *root*, so it falls
     * back to the documented default. A value that is present but malformed is
     * `null`: quietly rewriting it to `/` would tell the photographer to
     * configure something the server does not agree with.
     */
    private static function uploadPath(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return self::UPLOAD_PATH;
        }

        if (! is_string($value) || preg_match(self::PATH_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * `explicit` for 1, `implicit` for 2, `null` for anything else.
     *
     * Reported rather than hardcoded in the UI, because it is the value the
     * sftpgo binding is actually started with: the same `SFTPGO_FTPD_TLS_MODE`
     * feeds `SFTPGO_FTPD__BINDINGS__0__TLS_MODE` and this read. A literal in
     * the frontend would be a second source of truth for a setting that decides
     * whether a camera can connect at all.
     */
    private static function tlsMode(mixed $value): ?string
    {
        return match (is_string($value) || is_int($value) ? (string) $value : null) {
            '1' => 'explicit',
            '2' => 'implicit',
            default => null,
        };
    }

    /**
     * `null` unless the value is a real TCP port. A malformed port is treated as
     * absent rather than passed on, because the UI would otherwise offer a
     * photographer a port number the camera cannot use.
     */
    private static function port(mixed $value): ?int
    {
        if (is_int($value)) {
            return ($value >= 1 && $value <= 65535) ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $port = (int) $value;

        return ($port >= 1 && $port <= 65535) ? $port : null;
    }
}
