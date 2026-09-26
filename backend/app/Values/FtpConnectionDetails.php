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
     * The subpath a camera is configured with.
     *
     * Each account has its own physical directory, so the account root *is* the
     * target and the camera is told to upload to `/`. There is no subfolder to
     * pick in the UI by design (feature doc 7.9).
     */
    public const UPLOAD_PATH = '/';

    private function __construct(
        public readonly ?string $host,
        public readonly ?string $username,
        public readonly ?int $sftpPort,
        public readonly ?int $ftpsPort,
        public readonly ?int $pasvPortStart,
        public readonly ?int $pasvPortEnd,
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
        );
    }

    /**
     * A photographer can only be given a camera configuration when the host is
     * known and both ports are valid. The passive range is reported but does not
     * gate this: it is a firewall matter, not something the camera is told.
     */
    public function isConfigured(): bool
    {
        return $this->host !== null && $this->username !== null
            && $this->sftpPort !== null && $this->ftpsPort !== null;
    }

    /**
     * @return array{
     *     configured: bool,
     *     host: ?string,
     *     username: ?string,
     *     path: string,
     *     sftp_port: ?int,
     *     ftps_port: ?int,
     *     pasv_port_start: ?int,
     *     pasv_port_end: ?int
     * }
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'host' => $this->host,
            'username' => $this->username,
            'path' => self::UPLOAD_PATH,
            'sftp_port' => $this->sftpPort,
            'ftps_port' => $this->ftpsPort,
            'pasv_port_start' => $this->pasvPortStart,
            'pasv_port_end' => $this->pasvPortEnd,
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
