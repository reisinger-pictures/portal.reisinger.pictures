<?php
namespace App\Services;

use App\Enums\Brand;
use App\Models\Setting;
use App\Support\BrandRegistry;

/**
 * Brand-scoped settings resolver.
 *
 * Settings isolation uses the `brand` column on the `settings` table.
 * Reads scope by (key, brand), falling back to the canonical B2B ('rp')
 * row when no brand-specific row exists.
 */
class SettingResolver
{
    public function get(string $key, mixed $default = null): mixed
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        $value = Setting::where('key', $key)->where('brand', $brand)->value('value');
        if ($value !== null) {
            return $value;
        }

        // Fallback: B2B row ('rp' is the canonical/default brand for shared keys).
        if ($brand !== Brand::B2B->value) {
            $value = Setting::where('key', $key)->where('brand', Brand::B2B->value)->value('value');
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Read a setting value WITHOUT applying the brand scope — i.e. the raw key as stored.
     * Used for keys that are intentionally global (not brand-scoped).
     */
    public function getRaw(string $key): mixed
    {
        return Setting::where('key', $key)->value('value');
    }

    public function set(string $key, mixed $value): void
    {
        $brand = BrandRegistry::currentOrDefault()->value;
        Setting::updateOrCreate(
            ['key' => $key, 'brand' => $brand],
            ['value' => $value]
        );
    }

}
