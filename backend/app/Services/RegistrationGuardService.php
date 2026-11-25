<?php

namespace App\Services;

use App\Contracts\DnsResolver;
use App\Support\AtomicRateCounter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The public registration guard for `POST /api/auth/register`.
 *
 * Decision order (features/auth/05-registration-hardening.md): blocked domain,
 * rate limit, optional MX check. The two counters are *incremented before*
 * those decisions so blocked, rate-limited and already-registered addresses
 * all consume the budget — a bot cannot stay under the limit by varying the
 * address it tries. The existing-address branch in AuthController still
 * answers with the identical generic success (AUTH-2); only the attempt count
 * changes for it.
 */
final class RegistrationGuardService
{
    public function __construct(
        private readonly AtomicRateCounter $counter,
        private readonly RateLimiter $limiter,
        private readonly Repository $cache,
        private readonly DnsResolver $resolver,
    ) {}

    public function guard(Request $request, string $email): void
    {
        // The controller passes the already-normalized address. Lowercasing and
        // stripping a trailing dot again keeps the domain key stable if this
        // guard is ever called from another path.
        $domain = $this->domainOf($email);
        $ip = (string) ($request->ip() ?? '');

        $window = max(1, (int) config('registration.rate_limit.window_seconds', 300));
        $ipKey = $this->key('ip', $ip);
        $domainKey = $this->key('domain', $domain);

        // Count first, decide second (see the class docblock). This is the
        // count-first primitive: it never writes a blind `put()` that could
        // roll a concurrent worker's count back (D-38).
        $ipAttempts = $this->counter->count($ipKey, $window);
        $domainAttempts = $this->counter->count($domainKey, $window);

        if ($domain === '' || in_array($domain, $this->blockedDomains(), true)) {
            $this->reject($request, 'blocked_domain', $domain,
                'Registrierung mit dieser E-Mail-Domain ist nicht möglich.');
        }

        if ($ipAttempts === AtomicRateCounter::UNCOUNTABLE || $ipAttempts > (int) config('registration.rate_limit.per_ip', 3)) {
            $this->reject($request, 'rate_limit_ip', $domain,
                'Zu viele Registrierungsversuche. Bitte versuche es später erneut.', 429, $this->retryAfter($ipKey));
        }

        if ($domainAttempts === AtomicRateCounter::UNCOUNTABLE || $domainAttempts > (int) config('registration.rate_limit.per_domain', 3)) {
            $this->reject($request, 'rate_limit_domain', $domain,
                'Zu viele Registrierungsversuche. Bitte versuche es später erneut.', 429, $this->retryAfter($domainKey));
        }

        if ((bool) config('registration.mx_check.enabled', false) && ! $this->resolver->hasMailExchange($domain)) {
            $this->reject($request, 'mx_missing', $domain,
                'Für diese E-Mail-Domain ist keine Zustellung möglich.');
        }
    }

    /**
     * Forget both counters (and their `:timer` companions) for deterministic
     * test setup. Production never calls this: clearing a budget would reopen
     * a rate limit that an attacker is currently hitting.
     */
    public function clear(string $ip, string $domain): void
    {
        foreach ([$this->key('ip', $ip), $this->key('domain', $domain)] as $key) {
            $this->cache->forget($key);
            $this->cache->forget($key.':timer');
        }
    }

    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }

        return rtrim(strtolower(substr($email, $at + 1)), '.');
    }

    /**
     * @return list<string>
     */
    private function blockedDomains(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            (array) config('registration.blocked_domains', []),
        )));
    }

    /**
     * The counter writes the framework's `:timer` companion, so `availableIn`
     * reads the same window end the counter uses. Clamp to at least one second
     * to avoid a `Retry-After: 0` when the timer has just elapsed.
     */
    private function retryAfter(string $key): string
    {
        return (string) max(1, $this->limiter->availableIn($key));
    }

    private function reject(
        Request $request,
        string $reason,
        string $domain,
        string $error,
        int $status = 422,
        ?string $retryAfter = null,
    ): never {
        // No email in the context: the domain is enough to diagnose a blocked
        // wave, while an address is personal data and is never logged here.
        Log::warning('registration.rejected', [
            'reason' => $reason,
            'domain' => $domain,
            'ip' => (string) ($request->ip() ?? ''),
            'user_agent' => (string) ($request->userAgent() ?? ''),
            'timestamp' => now()->toIso8601String(),
        ]);

        $response = response()->json(['error' => $error], $status);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', $retryAfter);
        }

        throw new HttpResponseException($response);
    }

    private function key(string $scope, string $value): string
    {
        return 'registration:'.$scope.':'.sha1($value);
    }
}
