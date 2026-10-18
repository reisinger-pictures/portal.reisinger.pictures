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
