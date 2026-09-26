<?php

namespace App\Support;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Order;
use App\Services\BrandSettingsService;
use App\Values\BrandConfig;

class BrandRegistry
{
    private const CONTAINER_KEY = 'brand.context';

    private static ?array $brandConfigs = null;

    public static function fromHost(string $host): Brand
    {
        $config = self::resolveConfigFromHost($host);
        if ($config) {
            return Brand::tryFrom($config->id) ?? Brand::B2B;
        }
        if (str_ends_with(strtolower($host), '.localhost') || $host === 'localhost') {
            $parts = explode('.', $host);
            if (count($parts) >= 2 && $parts[0] !== 'localhost') {
                $candidate = $parts[0];
                if (Brand::tryFrom($candidate)) {
                    return Brand::tryFrom($candidate);
                }
            }
        }

        return Brand::B2B;
    }

    public static function config(): ?BrandConfig
    {
        if (! app()->bound(self::CONTAINER_KEY)) {
            return null;
        }
        $value = app(self::CONTAINER_KEY);
        if ($value instanceof BrandConfig) {
            return $value;
        }
        if ($value instanceof Brand) {
            return self::configForBrand($value->value);
        }

        return null;
    }

    public static function configOrDefault(): BrandConfig
    {
        return self::config() ?? self::defaultConfig();
    }

    public static function current(): ?Brand
    {
        $config = self::config();

        return $config ? Brand::tryFrom($config->id) : null;
    }

    public static function currentOrDefault(): Brand
    {
        return self::current() ?? Brand::B2B;
    }

    public static function currentId(): string
    {
        return self::currentIdOrNull() ?? Brand::B2B->value;
    }

    /**
     * Return the active brand id without applying the B2B fallback.
     *
     * Public resources are host-bound.  A missing context is therefore not the
     * same thing as the default `rp` context and must not silently widen access
     * to every brand.
     */
    public static function currentIdOrNull(): ?string
    {
        $config = self::config();
        if ($config === null) {
            return null;
        }

        $id = trim($config->id);

        return $id !== '' ? $id : null;
    }

    /**
     * Normalize a persisted/cast brand value to its raw id.
     */
    public static function normalizeId(mixed $brand): ?string
    {
        if ($brand instanceof \BackedEnum) {
            $brand = $brand->value;
        }

        if ($brand === null) {
            return null;
        }

        $id = trim((string) $brand);

        return $id !== '' ? $id : null;
    }

    /**
     * Check a resource against the request's active host brand.
     *
     * Unlike actor authorization, public URLs do not have a cross-brand mode:
     * both the active context and the resource must have the same non-empty
     * brand.  This intentionally rejects legacy null-brand resources.
     */
    public static function resourceMatchesCurrent(mixed $resourceBrand): bool
    {
        return self::resourceMatchesBrand($resourceBrand, self::currentIdOrNull());
    }

    /**
     * Compare a persisted brand value with an explicit expected brand.
     *
     * Keeping the comparison in one place prevents callers from mixing enum,
     * string, and null representations.  A null expected brand is never a
     * match: callers that intentionally support cross-brand actors must make
     * that decision before invoking this method.
     */
    public static function resourceMatchesBrand(mixed $resourceBrand, mixed $expectedBrand): bool
    {
        $resource = self::normalizeId($resourceBrand);
        $expected = self::normalizeId($expectedBrand);

        return $resource !== null && $expected !== null && $resource === $expected;
    }

    /**
     * Check a gallery group and its complete parent chain against a brand.
     *
     * Group-level checks are needed in addition to gallery-level checks: a
     * brand-bound management user must not be able to manage a group whose
     * parent is foreign or brand-less.  Missing parents and cycles fail closed
     * so a legacy/corrupt tree cannot widen access.
     */
    public static function galleryGroupTreeMatchesBrand(?GalleryGroup $group, mixed $expectedBrand): bool
    {
        if (! $group instanceof GalleryGroup || ! self::resourceMatchesBrand($group->brand, $expectedBrand)) {
            return false;
        }

        $visited = [];
        $depth = 0;
        $current = $group;

        while ($current instanceof GalleryGroup) {
            // AUTH-5: bound the ancestor walk to one lookup per level so an
            // over-deep (or corrupt) hierarchy cannot pin the request. A cycle
            // fails closed through the visited set below; exceeding the shared
            // traversal depth budget fails closed here.
            if ($depth++ > GalleryGroupSubtree::MAX_DEPTH) {
                return false;
            }

            $id = $current->getKey();
            if ($id === null || $id === '') {
                return false;
            }

            $key = (string) $id;
            if (isset($visited[$key]) || ! self::resourceMatchesBrand($current->brand, $expectedBrand)) {
                return false;
            }
            $visited[$key] = true;

            $parentId = $current->parent_id;
            if ($parentId === null || $parentId === '') {
                return true;
            }

            $current = GalleryGroup::find($parentId);
        }

        return false;
    }

    /**
     * Check a gallery group and its complete parent chain against the current
     * host brand.  This is the group-shaped counterpart of
     * galleryTreeMatchesCurrent() and is used by management surfaces.
     */
    public static function galleryGroupTreeMatchesCurrent(?GalleryGroup $group): bool
    {
        return self::galleryGroupTreeMatchesBrand($group, self::currentIdOrNull());
    }

    /**
     * Check a gallery and its complete parent-group chain against an explicit
     * brand.  A gallery's own brand is not sufficient: legacy rows can still
     * be attached to a foreign or null-brand group.
     *
     * The expected brand is explicit so management authorization can use the
     * acting user's brand while public surfaces use the host brand.  The
     * current-host wrapper below remains fail-closed when no context exists.
     */
    public static function galleryTreeMatchesBrand(?Gallery $gallery, mixed $expectedBrand): bool
    {
        if (! $gallery instanceof Gallery || ! self::resourceMatchesBrand($gallery->brand, $expectedBrand)) {
            return false;
        }

        $groupId = $gallery->gallery_group_id;
        if ($groupId === null || $groupId === '') {
            return true;
        }

        return self::galleryGroupTreeMatchesBrand(GalleryGroup::find($groupId), $expectedBrand);
    }

    /**
     * Check a gallery and its complete parent-group chain against the active
     * host brand.  Public URLs have no cross-brand mode, so a super-admin
     * actor must not change the result for this host-bound guard.
     */
    public static function galleryTreeMatchesCurrent(?Gallery $gallery): bool
    {
        return self::galleryTreeMatchesBrand($gallery, self::currentIdOrNull());
    }

    public static function prefix(): string
    {
        return self::configOrDefault()->prefix();
    }

    public static function set(Brand|BrandConfig|null $value): void
    {
        if ($value === null) {
            app()->offsetUnset(self::CONTAINER_KEY);
        } elseif ($value instanceof BrandConfig) {
            app()->instance(self::CONTAINER_KEY, $value);
        } else {
            $config = self::configForBrand($value->value);
            app()->instance(self::CONTAINER_KEY, $config ?? self::defaultConfig());
        }
    }

    public static function resolveFromOrder(Order $order): Brand
    {
        $brand = $order->brand;

        return $brand instanceof Brand ? $brand : Brand::B2B;
    }

    public static function resolveFromContract(Contract $contract): Brand
    {
        $brand = $contract->brand;

        return $brand instanceof Brand ? $brand : Brand::B2B;
    }

    public static function frontendUrl(?Brand $brand = null): string
    {
        $envUrl = config('app.frontend_url');
        if ($envUrl) {
            return rtrim($envUrl, '/');
        }
        $brand ??= self::currentOrDefault();
        $config = self::configForBrand($brand->value);
        if ($config?->frontendUrl) {
            return rtrim($config->frontendUrl, '/');
        }
        if ($config && isset($config->hostnames[0])) {
            return "https://{$config->hostnames[0]}";
        }

        return rtrim(config('app.url'), '/');
    }

    public static function reset(): void
    {
        self::set(null);
    }

    public static function configForBrand(string $brandId): ?BrandConfig
    {
        $configs = self::loadAllConfigs();

        return $configs[$brandId] ?? null;
    }

    public static function defaultConfig(): BrandConfig
    {
        return self::configForBrand('rp') ?? self::buildFromArray('rp', []);
    }

    public static function clearCache(): void
    {
        self::$brandConfigs = null;
    }

    private static function loadAllConfigs(): array
    {
        if (self::$brandConfigs !== null) {
            return self::$brandConfigs;
        }

        self::$brandConfigs = [];

        $brands = config('brands', []);
        foreach ($brands as $id => $data) {
            if (! ($data['is_active'] ?? true)) {
                continue;
            }
            self::$brandConfigs[$id] = self::buildFromArray($id, $data);
        }

        return self::$brandConfigs;
    }

    private static function buildFromArray(string $id, array $data): BrandConfig
    {
        // Overlay DB overrides over the config default (F3 — DB-Overlay, Option B).
        // Only whitelisted keys are honored; config-only keys (theme, logos,
        // hostnames, is_active) are never touched.
        $merged = $data;
        $overrides = app(BrandSettingsService::class)->overridesFor($id);
        foreach ($overrides as $configKey => $value) {
            if (str_contains($configKey, '.')) {
                [$section, $sub] = explode('.', $configKey, 2);
                $merged[$section] = $merged[$section] ?? [];
                $merged[$section][$sub] = $value;
            } else {
                $merged[$configKey] = $value;
            }
        }

        return new BrandConfig(
            id: $id,
            name: $merged['name'] ?? $id,
            theme: $merged['theme'] ?? 'rp',
            portalName: $merged['portal_name'] ?? $id,
            impressumUrl: $merged['impressum_url'] ?? null,
            logoPath: $merged['logo_path'] ?? null,
            logoEmailPath: $merged['logo_email_path'] ?? null,
            logoEmailPath2x: $merged['logo_email_path_2x'] ?? null,
            features: $merged['features'] ?? [],
            hostnames: $merged['hostnames'] ?? [],
            isActive: $merged['is_active'] ?? true,
            frontendUrl: $merged['frontend_url'] ?? null,
            fromAddress: $merged['from_address'] ?? null,
            fromName: $merged['from_name'] ?? null,
            accountingEmail: $merged['accounting_email'] ?? null,
            primaryColor: $merged['primary_color'] ?? '#1E5631',
            secondaryColor: $merged['secondary_color'] ?? '#A4B494',
        );
    }

    private static function resolveConfigFromHost(string $host): ?BrandConfig
    {
        $host = strtolower($host);
        $configs = self::loadAllConfigs();

        $exact = null;
        $subdomain = null;

        foreach ($configs as $config) {
            if (! $config->isActive) {
                continue;
            }
            foreach ($config->hostnames as $brandHost) {
                $brandHost = strtolower($brandHost);
                if ($host === $brandHost) {
                    $exact = $config;
                    break 2;
                }
                if (str_ends_with($host, '.'.$brandHost)) {
                    $subdomain = $config;
                }
            }
        }

        if ($exact) {
            return $exact;
        }

        if ($subdomain) {
            return $subdomain;
        }

        if (str_starts_with($host, 'www.')) {
            $withoutWww = substr($host, 4);
            foreach ($configs as $config) {
                if (! $config->isActive) {
                    continue;
                }
                foreach ($config->hostnames as $brandHost) {
                    $brandHost = strtolower($brandHost);
                    if ($withoutWww === $brandHost) {
                        return $config;
                    }
                    if (str_ends_with($withoutWww, '.'.$brandHost)) {
                        $subdomain = $config;
                    }
                }
            }
            if ($subdomain) {
                return $subdomain;
            }
        }

        return null;
    }
}
