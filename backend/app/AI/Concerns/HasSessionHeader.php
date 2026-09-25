<?php

namespace App\AI\Concerns;

/**
 * Shared session-affinity header logic for AI providers.
 *
 * The header name is configurable per deployment via `services.ai.session_header`
 * so gateways other than OpenCode Go (which expects `x-opencode-session`)
 * can be addressed without touching provider code. Header names use the
 * conservative `[A-Za-z0-9-]` allowlist; invalid names and mandatory provider
 * header names are never emitted. The configured prefix and per-request id
 * are sanitized to `[A-Za-z0-9._-]`, then the final value is clamped to 128
 * characters. An empty header name disables the feature.
 */
trait HasSessionHeader
{
    private const SESSION_VALUE_MAX_LENGTH = 128;

    private const RESERVED_SESSION_HEADER_NAMES = [
        'authorization',
        'content-type',
        'user-agent',
        'x-api-key',
        'anthropic-version',
    ];

    public function sessionHeaderName(): ?string
    {
        $configuredName = config('services.ai.session_header', 'x-opencode-session');
        if (! is_string($configuredName)) {
            return null;
        }

        $name = trim($configuredName);
        if ($name === '') {
            return null;
        }

        if (preg_match('/\A[A-Za-z0-9-]+\z/', $name) !== 1) {
            return null;
        }

        foreach (self::RESERVED_SESSION_HEADER_NAMES as $reservedName) {
            if (strcasecmp($name, $reservedName) === 0) {
                return null;
            }
        }

        return $name;
    }

    protected function sessionHeaderValue(?string $sessionId): ?string
    {
        if ($sessionId === null || trim($sessionId) === '') {
            return null;
        }

        if ($this->sessionHeaderName() === null) {
            return null;
        }

        $sanitizedSessionId = $this->sanitizeSessionValue($sessionId);
        if ($sanitizedSessionId === '') {
            return null;
        }

        $configuredPrefix = config('services.ai.session_prefix', 'portal-');
        if (! is_string($configuredPrefix)) {
            return null;
        }

        $prefix = $this->sanitizeSessionValue($configuredPrefix);
        $value = substr($prefix.$sanitizedSessionId, 0, self::SESSION_VALUE_MAX_LENGTH);

        return $value !== '' ? $value : null;
    }

    private function sanitizeSessionValue(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', trim($value)) ?? '';
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function withSessionHeader(array $headers, ?string $sessionId): array
    {
        $name = $this->sessionHeaderName();
        $value = $this->sessionHeaderValue($sessionId);

        if ($name === null || $value === null) {
            return $headers;
        }

        foreach (array_keys($headers) as $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                return $headers;
            }
        }

        $headers[$name] = $value;

        return $headers;
    }
}
