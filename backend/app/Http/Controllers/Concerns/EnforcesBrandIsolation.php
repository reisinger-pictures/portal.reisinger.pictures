<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Brand;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * Shared brand-isolation helpers for management controllers / requests.
 *
 * Trust model (see AGENTS.md / features): only a persisted Super-Admin with
 * `brand === null` may act across brands. A legacy registered null-brand
 * account is invalid, while a transient guest is handled separately through
 * active invite provenance. Every brand-bound actor (`brand !== null`,
 * including `admin`) is isolated to resources of their own brand; a resource
 * without a brand (`null`) counts as foreign for a brand-bound actor.
 */
trait EnforcesBrandIsolation
{
    /**
     * Normalize a model's `brand` attribute (the AsBrand cast may return either a
     * Brand enum or a plain string for values not covered by the enum) to its raw
     * string value. `null` is a no-brand value; it is only cross-brand for a
     * trusted persisted Super-Admin after the trust check below.
     */
    protected function brandValue(?object $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $brand = $model->brand ?? null;
        if ($brand === null) {
            return null;
        }

        return $brand instanceof Brand ? $brand->value : (string) $brand;
    }

    /**
     * Only a persisted Super-Admin with a null brand is cross-brand. In
     * particular, a null-brand ordinary user and a transient guest must never
     * be treated as an all-brand actor by a shared controller.
     */
    protected function isCrossBrand(?User $user): bool
    {
        return $user !== null
            && app(AuthorizationService::class)->isTrustedCrossBrandActor($user);
    }

    /**
     * True when an actor may not act on the resource's brand. Invalid legacy
     * null-brand actors fail closed, even when their role predicate would have
     * been true before the reserved-state check.
     */
    protected function isBrandMismatch(?User $actor, object $resource): bool
    {
        if ($actor === null) {
            return true;
        }

        $authorization = app(AuthorizationService::class);
        if ($authorization->isReservedNullBrandActor($actor)
            || $authorization->isTransientGuest($actor)) {
            return true;
        }

        if ($this->isCrossBrand($actor)) {
            return false;
        }

        return $this->brandValue($actor) !== $this->brandValue($resource);
    }
}
