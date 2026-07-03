<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagementMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user->is_admin || $user->is_super_admin) {
            return $next($request);
        }

        $isAllowed = false;
        $path = $request->path();

        if ($user->is_photographer) {
            $allowedPrefixes = ['api/management/galleries*', 'api/management/gallery-groups*', 'api/management/upload*', 'api/management/ftp*', 'api/management/invites*', 'api/management/stats*', 'api/management/logs*', 'api/management/orders/quote-link*', 'api/management/coupons*', 'api/management/tenants*'];
            foreach ($allowedPrefixes as $prefix) {
                if ($request->is($prefix)) {
                    $isAllowed = true; break;
                }
            }
        }

        if ($user->is_org_admin) {
            // Org Admin dürfen nur die User-Verwaltung und rudimentäre Analytics sehen
            $allowedPrefixes = ['api/management/users*', 'api/management/roles*', 'api/management/stats*', 'api/management/logs*', 'api/management/tenants*'];
            foreach ($allowedPrefixes as $prefix) {
                if ($request->is($prefix)) {
                    $isAllowed = true; break;
                }
            }
        }

        if (!$isAllowed) {
            return response()->json(['error' => 'Zutritt verweigert. Keine ausreichenden Berechtigungen.'], 403);
        }

        return $next($request);
    }
}
