<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ProtectApiMutations
{
    private const CSRF_COOKIE = 'XSRF-TOKEN';

    private const CSRF_HEADER = 'X-XSRF-TOKEN';

    /** @var array<int, string> */
    private const MULTIPART_PATHS = [
        'api/management/upload',
        'api/management/settings/watermark',
        'api/management/invoices/extract-offer',
        'api/model-registration/*',
        'api/model-profil/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        if ($this->isUnsafeMethod($request)) {
            if (! $this->hasAllowedMutationContentType($request)) {
                return response()->json(['error' => 'Unsupported mutation content type.'], 415);
            }

            $isTrustedIngress = $request->is('api/webhooks/stripe')
                || $request->is('api/internal/ftp/upload-event');

            if (! $isTrustedIngress && ! $this->hasAllowedOrigin($request)) {
                return response()->json(['error' => 'Cross-origin mutation rejected.'], 403);
            }

            if (! $isTrustedIngress && $this->hasJwtCookie($request) && ! $this->hasValidCsrfToken($request)) {
                return response()->json(['error' => 'CSRF token missing or invalid.'], 403);
            }
        }

        $response = $next($request);
        $response->headers->setCookie($this->csrfCookie($request));

        return $response;
    }

    private function isUnsafeMethod(Request $request): bool
    {
        return ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function hasAllowedMutationContentType(Request $request): bool
    {
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'), 2)[0]));
        if ($contentType === '') {
            // The boundary governs what a body may be, not whether one exists.
            // A request without a body cannot submit a form — DELETE and the
            // zero-payload POSTs are body-less by design — so it is allowed
            // only while it really carries nothing.
            return trim((string) $request->getContent()) === '';
        }

        if ($contentType === 'application/json') {
            return true;
        }

        if ($contentType !== 'multipart/form-data') {
            return false;
        }

        foreach (self::MULTIPART_PATHS as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    private function hasAllowedOrigin(Request $request): bool
    {
        $hasOrigin = $request->headers->has('Origin');
        $hasReferer = $request->headers->has('Referer');

        if (! $hasOrigin && ! $hasReferer) {
            // Browser cookie credentials never get a missing-origin exception.
            // Cookie-less CLI, bearer-token, and token-link callers may omit it.
            $fetchSite = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));

            return ! $this->hasJwtCookie($request)
                && ($fetchSite === '' || $fetchSite === 'same-origin');
        }

        $allowedOrigins = array_values(array_filter([
            $this->originFromUrl($request->getSchemeAndHttpHost(), true),
            $this->originFromUrl((string) config('app.frontend_url', ''), true),
        ]));

        if ($hasOrigin) {
            $origin = $this->originFromUrl((string) $request->header('Origin'), true);
            if ($origin === null || ! in_array($origin, $allowedOrigins, true)) {
                return false;
            }
        }

        if ($hasReferer) {
            $refererOrigin = $this->originFromUrl((string) $request->header('Referer'), false);
            if ($refererOrigin === null || ! in_array($refererOrigin, $allowedOrigins, true)) {
                return false;
            }
        }

        $fetchSite = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));

        return $fetchSite === '' || $fetchSite === 'same-origin';
    }

    private function originFromUrl(string $url, bool $strictOrigin): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        if ($strictOrigin && (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment']))) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = rtrim(strtolower((string) $parts['host']), '.');
        if ($host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    private function hasJwtCookie(Request $request): bool
    {
        return $request->hasCookie((string) config('jwt.cookie_key_name', 'rp_jwt'))
            || $request->hasCookie((string) config('jwt.refresh_cookie_key_name', 'rp_jwt_refresh'));
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        $cookieToken = $request->cookie(self::CSRF_COOKIE);
        $headerToken = $request->header(self::CSRF_HEADER);

        return is_string($cookieToken)
            && is_string($headerToken)
            && hash_equals($cookieToken, $headerToken)
            && $this->isSignedCsrfTokenValid($cookieToken);
    }

    private function isSignedCsrfTokenValid(string $token): bool
    {
        $key = $this->csrfSigningKey();
        if ($key === null || ! preg_match('/\A([0-9]{10})\.([a-f0-9]{64})\.([a-f0-9]{64})\z/D', $token, $matches)) {
            return false;
        }

        $expiresAt = (int) $matches[1];
        if ($expiresAt <= time()) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $matches[1].'.'.$matches[2], $key);

        return hash_equals($expectedSignature, $matches[3]);
    }

    private function csrfSigningKey(): ?string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decodedKey = base64_decode(substr($key, 7), true);

            return is_string($decodedKey) && $decodedKey !== '' ? $decodedKey : null;
        }

        return $key;
    }

    private function csrfCookie(Request $request): Cookie
    {
        $token = $request->cookie(self::CSRF_COOKIE);
        if (! is_string($token) || ! $this->isSignedCsrfTokenValid($token)) {
            $token = $this->createCsrfToken();
        }

        return cookie(self::CSRF_COOKIE, $token ?? '', $this->csrfTtlMinutes(), '/', null, $request->isSecure(), false, false, 'Lax');
    }

    private function createCsrfToken(): ?string
    {
        $key = $this->csrfSigningKey();
        if ($key === null) {
            return null;
        }

        $expiresAt = (string) (time() + $this->csrfTtlMinutes() * 60);
        $nonce = bin2hex(random_bytes(32));
        $signature = hash_hmac('sha256', $expiresAt.'.'.$nonce, $key);

        return $expiresAt.'.'.$nonce.'.'.$signature;
    }

    /**
     * The token must outlive every session it protects. A shorter window would
     * reject the next mutation of a still-valid session with `403` before the
     * client can obtain a new token, so the lifetime follows the refresh
     * session instead of an independent timer.
     */
    private function csrfTtlMinutes(): int
    {
        return max(1, (int) config('jwt.refresh_ttl', 10080));
    }
}
