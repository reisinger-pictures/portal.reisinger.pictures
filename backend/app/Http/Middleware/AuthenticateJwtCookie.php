<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make the browser's auth cookie the credential it is.
 *
 * The JWT package resolves a token from the `Authorization` header first and
 * from a request field named `token` second — both **before** the cookie
 * parser. A browser mutation whose body carries a `token` (redeeming an
 * organization or gallery invite) therefore looked anonymous although the
 * session cookie was present, and the redeem fell into the guest branch that
 * demands `name` and `password`.
 *
 * A request without an `Authorization` header but with the auth cookie is a
 * browser session, so the cookie is promoted to the header the parsers read
 * first. An explicit bearer token keeps precedence, and a request without the
 * cookie is untouched — server clients and token-link flows stay as they are.
 */
class AuthenticateJwtCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('jwt.cookie_key_name', 'rp_jwt');
        $token = $request->cookie($cookieName);

        if (! $request->headers->has('Authorization') && is_string($token) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
