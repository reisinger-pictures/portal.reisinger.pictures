<?php

namespace App\Support;

use App\Enums\Brand;

/**
 * The brand a console command may be pointed at.
 *
 * The brand context of the application is bound to a `Brand` enum case, and
 * `BrandRegistry::current()` falls back to B2B for an id that is not a case.
 * A command that offers a `--brand=` option must therefore either resolve the
 * id to a real brand or refuse it — silently reading B2B while the operator
 * asked for another brand would report the wrong values under the wrong
 * label.
 */
final class BrandSelection
{
    /**
     * The brand the caller asked for, or null when the id is empty (the
     * command then keeps the context the application already has).
     */
    public static function resolve(?string $id): ?Brand
    {
        if ($id === null || trim($id) === '') {
            return null;
        }

        return Brand::tryFrom(trim($id));
    }

    /**
     * The ids a caller may choose.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return array_map(
            static fn (Brand $brand): string => $brand->value,
            Brand::cases(),
        );
    }
}
