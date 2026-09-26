<?php

namespace App\Values;

use Illuminate\Support\Facades\Config;

/**
 * Admin credentials for the SFTPGo Admin API, used only for the JWT fallback
 * when no API key is configured (P1-M22, feature doc 7.6).
 *
 * Verified against the running instance: the token endpoint is
 * `GET /api/v2/token` with `security: [BasicAuth]` (not POST — a POST answers
 * 405), so these are HTTP basic credentials for the SFTPGo admin user that
 * `SFTPGO_DEFAULT_ADMIN_USERNAME` / `_PASSWORD` create.
 *
 * The value is intentionally not stringable and not serialisable:
 * `__debugInfo()` and `__serialize()` are reduced to non-secret markers, so an
 * accidental `dump()`, `dd()`, `Log::info()` with the object or a
 * `serialize()` cannot carry the secret out. `print_r()` / `var_export()`
 * bypass both hooks — that is a PHP limitation no DTO can escape, which is why
 * nothing in the client ever hands this object to a logger.
 */
readonly class SftpGoAdminCredentials
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}

    /**
     * Returns null when either half is missing — the caller then decides
     * whether the JWT path is available at all, instead of sending a
     * half-configured Basic header to the service.
     */
    public static function fromConfig(): ?self
    {
        $username = Config::get('services.sftpgo.admin_username');
        $password = Config::get('services.sftpgo.admin_password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return null;
        }

        return new self($username, $password);
    }

    /**
     * @return array{username: bool, password: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'username' => true,
            'password' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['username' => $this->username];
    }
}
