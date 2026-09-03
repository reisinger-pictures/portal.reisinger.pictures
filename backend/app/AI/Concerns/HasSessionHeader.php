<?php
namespace App\AI\Concerns;

/**
 * Shared session-affinity header logic for AI providers.
 *
 * The header name is configurable per deployment via `services.ai.session_header`
 * so gateways other than OpenCode Go (which expects `x-opencode-session`)
 * can be addressed without touching provider code. A per-request session id
 * is sanitized, prefixed (`services.ai.session_prefix`) and clamped to
 * 128 chars. An empty header name disables the feature.
 */
trait HasSessionHeader
{
    public function sessionHeaderName(): ?string
    {
        $name = (string) config('services.ai.session_header', 'x-opencode-session');

        return trim($name) !== '' ? trim($name) : null;
    }

    protected function sessionHeaderValue(?string $sessionId): ?string
    {
        if ($sessionId === null || trim($sessionId) === '') {
            return null;
        }

        if ($this->sessionHeaderName() === null) {
            return null;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '-', trim($sessionId)) ?? '';
        if ($sanitized === '') {
            return null;
        }

        $prefix = (string) config('services.ai.session_prefix', 'portal-');
        $value = substr($prefix.$sanitized, 0, 128);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function withSessionHeader(array $headers, ?string $sessionId): array
    {
        $name = $this->sessionHeaderName();
        $value = $this->sessionHeaderValue($sessionId);

        if ($name !== null && $value !== null) {
            $headers[$name] = $value;
        }

        return $headers;
    }
}
