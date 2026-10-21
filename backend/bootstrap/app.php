<?php

use App\Http\Middleware\BrandContextMiddleware;
use App\Http\Middleware\ManagementMiddleware;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(BrandContextMiddleware::class);
        $middleware->append(SetSecurityHeaders::class);
        // Registriere den Alias für unsere Admin-Middleware
        $middleware->alias([
            'management' => ManagementMiddleware::class,
            'super_admin' => SuperAdminMiddleware::class,
        ]);

        // Diese App ist API-only und hat keine `login`-Route. Laravels
        // `withMiddleware()` setzt vor diesem Callback den Framework-Default
        // `redirectGuestsTo(fn () => route('login'))`
        // (vendor/laravel/framework .../Configuration/ApplicationBuilder.php:291).
        // Ohne JSON-Accept ruft `Authenticate::redirectTo()` ihn auf, `route('login')`
        // wirft `RouteNotFoundException` — der 500 entsteht im Middleware-Stack,
        // also bevor die `AuthenticationException` überhaupt geworfen wird. Der
        // Kernel fängt stattdessen die `RouteNotFoundException` und rendert sie
        // als 500 (und protokolliert sie); der Render-Callback unten sieht sie
        // nie (Produktion 2026-10-18: `curl /api/auth/me` → 500, mit
        // `Accept: application/json` → 401).
        // `null` ersetzt den Default durch `fn () => null`: kein Redirect-Ziel,
        // kein Route-Lookup, `AuthenticationException` wird überhaupt erst geworfen.
        $middleware->redirectGuestsTo(null);

        // The API is only reachable through Caddy: the FastCGI port is not
        // published to the host, and Caddy sets `X-Forwarded-For` from the
        // real peer while ignoring the header a client sends.
        //
        // Without trusting the proxy, every request arrives from the proxy's
        // container address (measured in production: 172.18.0.3 for every
        // request), and every rate limiter collapses into ONE portal-wide
        // bucket: the login limiter (5 attempts per minute), the API limiter,
        // the checkout quota (10/h and 30/d per IP) and the Turnstile
        // thresholds. The stored `orders.ip_address` named the proxy instead
        // of the customer, and the S3/S7 runbook signals counted one bucket.
        //
        // `at: 'REMOTE_ADDR'` trusts exactly the direct peer — the proxy — and
        // nothing else. Symfony then takes the RIGHTMOST entry of the
        // forwarded chain, which is the one the proxy itself wrote, so a
        // client-supplied entry cannot replace the client address. (`at: '*'`
        // would trust every address in the chain and fall back to the
        // leftmost entry — the spoofable one.)
        //
        // Only `X-Forwarded-For` and `X-Forwarded-Proto` are trusted: the host
        // header stays the one Caddy matched.
        $middleware->trustProxies(
            at: 'REMOTE_ADDR',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Der Redirect-Pfad ist oben entfernt; hier bekommt der 401 auch ohne
        // `Accept: application/json` einen JSON-Body. Status und Body bleiben
        // identisch zu dem, was JSON-Clients schon heute erhalten.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 401);
            }
        });
    })->create();
