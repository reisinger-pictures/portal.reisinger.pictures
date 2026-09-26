<?php

namespace App\AI\Concerns;

/**
 * Mandatory HTTP User-Agent for all outgoing AI provider requests.
 *
 * The value is intentionally hardcoded — no env key, no config entry — so it
 * cannot drift per deployment: OpenCode Go requires proper, non-generic client
 * identification and blocks generic defaults such as Guzzle's `GuzzleHttp/x.y`.
 * Applied by every provider via `withUserAgent()` inside `buildHeaders()`.
 */
trait HasUserAgent
{
    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function withUserAgent(array $headers): array
    {
        $headers['User-Agent'] = 'reisinger.pictures Portal';

        return $headers;
    }
}
