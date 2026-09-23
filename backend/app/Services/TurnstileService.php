<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const ACTION = 'checkout';

    private const MAX_TOKEN_LENGTH = 2048;

    private const CDATA_MAX_LENGTH = 32;

    private const CDATA_PREFIX = 'checkout-';

    /**
     * Cloudflare's documented always-pass test pair. It is accepted only when
     * the explicit test-only opt-in is enabled; arbitrary dummy credentials
     * never receive a compatibility bypass.
     */
    private const DUMMY_SITE_KEY = '1x00000000000000000000AA';

    private const DUMMY_SECRET = '1x0000000000000000000000000000000AA';

    public function isEnabled(): bool
    {
        $siteKey = config('services.turnstile.site_key');
        $secret = config('services.turnstile.secret');

        return is_string($siteKey)
            && trim($siteKey) !== ''
            && is_string($secret)
            && trim($secret) !== '';
    }

    /**
     * Build the bounded cData value sent to Turnstile and checked after
     * Siteverify. Short identifiers remain literal (`checkout-{id}`). Longer
     * identifiers (notably UUIDs) use the first 23 characters of the ID. The
     * prefix is 9 characters, so the result is always at most 32 characters
     * and needs no asynchronous or cryptographic browser support.
     */
    public static function cDataForUserId(string|int $userId): string
    {
        $id = trim((string) $userId);
        if ($id === '') {
            $id = 'anonymous';
        }

        $literal = self::CDATA_PREFIX.$id;
        if (strlen($literal) <= self::CDATA_MAX_LENGTH) {
            return $literal;
        }

        return self::CDATA_PREFIX.substr($id, 0, self::CDATA_MAX_LENGTH - strlen(self::CDATA_PREFIX));
    }

    /**
     * Cloudflare enforces the token's single-use property and five-minute
     * lifetime. The application deliberately does not cache tokens or
     * reimplement those semantics locally.
     */
    public function assertValid(string $token, User $user, ?string $remoteIp = null): void
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            $this->rejectInvalidToken();
        }

        $payload = [
            'secret' => (string) config('services.turnstile.secret'),
            'response' => $token,
        ];

        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(3)
                ->post(self::VERIFY_URL, $payload);
        } catch (ConnectionException) {
            $this->rejectUnavailable();

            return;
        }

        if (! $response->successful()) {
            $this->rejectUnavailable();
        }

        $verification = $response->json();

        if (! is_array($verification) || ! array_key_exists('success', $verification)) {
            $this->rejectUnavailable();
        }

        if ($verification['success'] !== true) {
            $this->rejectInvalidToken();
        }

        // The official dummy credentials deliberately return a reduced
        // Siteverify response without action/cData/hostname. This narrow path
        // is available only to the CI/test configuration and exact key pair;
        // it is not a general bypass for missing or invalid challenge data.
        if ($this->dummyCompatibilityEnabled()) {
            return;
        }

        // Siteverify returns the action/cData/hostname bound to the widget token;
        // these values are checked server-side rather than trusted from the client.
        if (($verification['action'] ?? null) !== self::ACTION
            || ($verification['cdata'] ?? null) !== $this->customerData($user)
            || ! $this->hostnameIsAllowed($verification['hostname'] ?? null)) {
            $this->rejectInvalidToken();
        }
    }

    private function customerData(User $user): string
    {
        return self::cDataForUserId((string) $user->getAuthIdentifier());
    }

    private function dummyCompatibilityEnabled(): bool
    {
        if (! (bool) config('services.turnstile.allow_dummy_test_keys', false)) {
            return false;
        }

        $testEnvironment = app()->environment('testing')
            || (app()->environment('local') && filter_var(env('CI', false), FILTER_VALIDATE_BOOL));
        if (! $testEnvironment) {
            return false;
        }

        return hash_equals(self::DUMMY_SITE_KEY, (string) config('services.turnstile.site_key'))
            && hash_equals(self::DUMMY_SECRET, (string) config('services.turnstile.secret'));
    }

    private function hostnameIsAllowed(mixed $hostname): bool
    {
        if (! is_string($hostname) || $hostname === '') {
            return false;
        }

        return in_array(strtolower($hostname), $this->allowedHostnames(), true);
    }

    /**
     * @return list<string>
     */
    private function allowedHostnames(): array
    {
        $configured = config('services.turnstile.allowed_hostnames', '');
        $values = is_array($configured)
            ? $configured
            : explode(',', (string) $configured);

        $hostnames = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $hostname): string => strtolower(trim((string) $hostname)),
                $values
            ),
            static fn (string $hostname): bool => $hostname !== ''
        )));

        if ($hostnames !== []) {
            return $hostnames;
        }

        // The widget runs in the decoupled frontend, so FRONTEND_URL is the
        // appropriate safe fallback; APP_URL is used only for single-host installs.
        $fallbackUrl = config('app.frontend_url') ?: config('app.url');
        $fallbackHostname = parse_url((string) $fallbackUrl, PHP_URL_HOST);

        return is_string($fallbackHostname) && $fallbackHostname !== ''
            ? [strtolower($fallbackHostname)]
            : [];
    }

    private function rejectInvalidToken(): never
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Die Sicherheitsprüfung ist fehlgeschlagen. Bitte versuchen Sie es erneut.',
            'turnstile_required' => true,
        ], 403));
    }

    private function rejectUnavailable(): never
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Die Sicherheitsprüfung ist vorübergehend nicht verfügbar. Bitte versuchen Sie es später erneut.',
        ], 503));
    }
}
