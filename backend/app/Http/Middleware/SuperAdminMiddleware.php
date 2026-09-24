<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        $authorization = app(AuthorizationService::class);
        if (! $user || ! $authorization->isSuperAdmin($user)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $actorBrand = BrandRegistry::normalizeId($user->brand);
        if ($actorBrand !== null && $actorBrand !== BrandRegistry::currentIdOrNull()) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        return $next($request);
    }
}
