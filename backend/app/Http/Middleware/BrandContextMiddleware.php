<?php

namespace App\Http\Middleware;

use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrandContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // In tests the environment controls config('app.brand') directly.
        if (app()->environment('testing')) {
            return $next($request);
        }

        $host = $request->getHost();

        // Dev-Fallback: Da der Vite-Proxy API-Calls umschreibt und das Backend
        // lokal immer 'portal.test' sieht, lesen wir im lokalen Modus den Host
        // zusätzlich aus dem Referer-Header aus, um den ATR-Brand emulieren zu können.
        // (Mit dem 2-Instanzen-Vite-Setup hat jedes Proxy-Target den korrekten Host;
        // der Referer-Backstop bleibt als Safety-Net für gemischte Setups.)
        if (app()->environment('local') && $request->headers->has('referer')) {
            $refererHost = parse_url($request->header('referer'), PHP_URL_HOST);
            if ($refererHost) {
                $host = $refererHost;
            }
        }

        BrandRegistry::set(BrandRegistry::fromHost($host));

        return $next($request);
    }
}
