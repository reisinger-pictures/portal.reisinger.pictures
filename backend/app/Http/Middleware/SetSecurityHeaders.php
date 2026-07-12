<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets baseline security headers on every API response.
 *
 * No CSP is applied here: this serves a pure JSON API consumed by a separate
 * SPA, and CSP is enforced at the frontend reverse proxy (nginx). Adding it
 * here would have no effect on the browser's document policy.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS only over HTTPS — prevents pinning an HTTP dev environment.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
