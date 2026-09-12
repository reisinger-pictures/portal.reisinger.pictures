<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Brand;
use App\Models\User;

/**
 * Shared brand-isolation helpers for management controllers / requests.
 *
 * Trust model (see AGENTS.md / features): the cross-brand actor
 * (`brand === null`, e.g. `super_admin`) may act across brands. Every
 * brand-bound actor (`brand !== null`, including `admin`) is isolated to
 * resources of their own brand; a resource without a brand (`null`) counts as
 * foreign for a brand-bound actor.
 */
trait EnforcesBrandIsolation
{
    /**
     * Normalize a model's `brand` attribute (the AsBrand cast may return either a
     * Brand enum or a plain string for values not covered by the enum) to its raw
     * string value. `null` means cross-brand / no brand.
     */
    protected function brandValue(object|null $model): ?string
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
     * A cross-brand actor (brand = null) is allowed to act across all brands.
     */
    protected function isCrossBrand(?User $user): bool
    {
        return $this->brandValue($user) === null;
    }

    /**
     * True when a brand-bound actor targets a resource of a different brand
     * (including a brand-less resource). Always false for a cross-brand actor.
     */
    protected function isBrandMismatch(?User $actor, object $resource): bool
    {
        $actorBrand = $this->brandValue($actor);
        if ($actorBrand === null) {
            return false;
        }

        return $actorBrand !== $this->brandValue($resource);
    }
}
