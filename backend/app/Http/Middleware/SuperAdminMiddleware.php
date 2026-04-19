<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth('api')->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
