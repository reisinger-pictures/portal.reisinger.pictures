<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrandContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        
        // Dev-Fallback: Da der Vite-Proxy API-Calls umschreibt und das Backend 
        // lokal immer 'portal.test' sieht, lesen wir im lokalen Modus den Host 
        // zusätzlich aus dem Referer-Header aus, um 'all-the.rest' emulieren zu können.
        if (app()->environment('local') && $request->headers->has('referer')) {
            $refererHost = parse_url($request->header('referer'), PHP_URL_HOST);
            if ($refererHost) {
                $host = $refererHost;
            }
        }

        $brand = str_contains($host, 'all-the.rest') ? 'all-the.rest' : 'reisinger.pictures';
        config(['app.brand' => $brand]);

        return $next($request);
    }
}
