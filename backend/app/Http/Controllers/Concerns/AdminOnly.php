<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Brand;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;

/**
 * Shared admin gate + brand context for model-registration management endpoints.
 *
 * The gate is `AuthorizationService::isAdmin` (Admin or Super-Admin). A
 * brand-bound admin always operates in their own brand; only a persisted
 * Super-Admin with `brand = null` may operate in the current request brand.
 */
trait AdminOnly
{
    protected function authorizeAdmin(): void
    {
        $user = auth('api')->user();
        $authorization = app(AuthorizationService::class);

        if (! $user
            || $authorization->isReservedNullBrandActor($user)
            || ! $authorization->isAdmin($user)) {
            abort(response()->json(['error' => 'Keine Berechtigung.'], 403));
        }
    }

    protected function adminBrand(): string
    {
        $brand = auth('api')->user()?->brand;

        if ($brand instanceof Brand) {
            return $brand->value;
        }

        if ($brand !== null) {
            return (string) $brand;
        }

        return BrandRegistry::currentOrDefault()->value;
    }
}
