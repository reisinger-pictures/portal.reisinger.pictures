<?php

namespace App\Support;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\Order;
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
        if (!app()->bound(self::CONTAINER_KEY)) {
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
        return self::config()?->id ?? Brand::B2B->value;
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
            if (!($data['is_active'] ?? true)) {
                continue;
            }
            self::$brandConfigs[$id] = self::buildFromArray($id, $data);
        }

        return self::$brandConfigs;
    }

    private static function buildFromArray(string $id, array $data): BrandConfig
    {
        return new BrandConfig(
            id: $id,
            name: $data['name'] ?? $id,
            theme: $data['theme'] ?? 'rp',
            portalName: $data['portal_name'] ?? $id,
            impressumUrl: $data['impressum_url'] ?? null,
            logoPath: $data['logo_path'] ?? null,
            logoEmailPath: $data['logo_email_path'] ?? null,
            logoEmailPath2x: $data['logo_email_path_2x'] ?? null,
            features: $data['features'] ?? [],
            hostnames: $data['hostnames'] ?? [],
            isActive: $data['is_active'] ?? true,
            frontendUrl: $data['frontend_url'] ?? null,
            fromAddress: $data['from_address'] ?? null,
            fromName: $data['from_name'] ?? null,
            accountingEmail: $data['accounting_email'] ?? null,
            primaryColor: $data['primary_color'] ?? '#1E5631',
            secondaryColor: $data['secondary_color'] ?? '#A4B494',
        );
    }

    private static function resolveConfigFromHost(string $host): ?BrandConfig
    {
        $host = strtolower($host);
        $configs = self::loadAllConfigs();

        $exact = null;
        $subdomain = null;

        foreach ($configs as $config) {
            if (!$config->isActive) {
                continue;
            }
            foreach ($config->hostnames as $brandHost) {
                $brandHost = strtolower($brandHost);
                if ($host === $brandHost) {
                    $exact = $config;
                    break 2;
                }
                if (str_ends_with($host, '.' . $brandHost)) {
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
                if (!$config->isActive) {
                    continue;
                }
                foreach ($config->hostnames as $brandHost) {
                    $brandHost = strtolower($brandHost);
                    if ($withoutWww === $brandHost) {
                        return $config;
                    }
                    if (str_ends_with($withoutWww, '.' . $brandHost)) {
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
