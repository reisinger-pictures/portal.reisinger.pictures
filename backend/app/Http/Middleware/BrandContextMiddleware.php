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
        if (app()->environment('testing')) {
            return $next($request);
        }

        BrandRegistry::set(BrandRegistry::fromHost($this->resolveHost($request)));

        return $next($request);
    }

    private function resolveHost(Request $request): string
    {
        if (app()->environment('local')) {
            // Vite proxy rewrites Host → brand lost. The Referer header (standard HTTP,
            // sent by browsers automatically) carries the original subdomain.
            $refererHost = parse_url($request->header('referer', ''), PHP_URL_HOST);
            if ($refererHost) {
                return $refererHost;
            }
        }

        return $request->getHost();
    }
}
