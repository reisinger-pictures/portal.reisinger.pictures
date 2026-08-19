<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if (! $user || ! app(AuthorizationService::class)->isSuperAdmin($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
