<?php

namespace App\Support;

use App\Enums\Brand;
use App\Models\Brand as BrandModel;
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
        // Fallback for tests / missing brands table
        if (str_starts_with(strtolower($host), 'buy.')) {
            return Brand::SRP;
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

    public static function isSrp(): bool
    {
        return self::current() === Brand::SRP;
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
        return $order->brand ?? Brand::B2B;
    }

    public static function resolveFromContract(Contract $contract): Brand
    {
        return $contract->brand ?? Brand::B2B;
    }

    public static function frontendUrl(?Brand $brand = null): string
    {
        $brand ??= self::currentOrDefault();
        $envUrl = config('app.frontend_url');
        if ($envUrl) {
            return rtrim($envUrl, '/');
        }
        $config = self::configForBrand($brand->value);
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
        if (isset($configs[$brandId])) {
            return $configs[$brandId];
        }
        // Fallback for tests / missing brands table
        return self::hardcodedConfig($brandId);
    }

    public static function defaultConfig(): BrandConfig
    {
        return self::configForBrand('rp') ?? self::hardcodedConfig('rp');
    }

    private static function hardcodedConfig(string $brandId): ?BrandConfig
    {
        return match ($brandId) {
            'rp' => new BrandConfig(
                id: 'rp',
                name: 'Reisinger Pictures',
                theme: 'rp',
                portalName: 'Reisinger Foto Portal',
                impressumUrl: 'https://reisinger.pictures/impressum/',
                logoPath: '/brands/rp/android-chrome-192x192.png',
                features: ['coupons' => true, 'orgs' => true, 'volume_licensing' => false],
                hostnames: ['portal.reisinger.pictures'],
            ),
            'srp' => new BrandConfig(
                id: 'srp',
                name: 'SRP Reisinger',
                theme: 'srp',
                portalName: 'Reisinger Foto Portal',
                impressumUrl: 'https://buy.reisinger.pictures/impressum/',
                logoPath: '/brands/srp/android-chrome-192x192.png',
                features: ['coupons' => false, 'orgs' => false, 'volume_licensing' => true],
                hostnames: ['buy.reisinger.pictures'],
            ),
            default => null,
        };
    }

    private static function resolveConfigFromHost(string $host): ?BrandConfig
    {
        $host = strtolower($host);
        $configs = self::loadAllConfigs();
        foreach ($configs as $config) {
            if (!$config->isActive) {
                continue;
            }
            foreach ($config->hostnames as $brandHost) {
                $brandHost = strtolower($brandHost);
                if ($host === $brandHost || str_ends_with($host, '.' . $brandHost)) {
                    return $config;
                }
                if (str_starts_with($host, 'www.')) {
                    $withoutWww = substr($host, 4);
                    if ($withoutWww === $brandHost || str_ends_with($withoutWww, '.' . $brandHost)) {
                        return $config;
                    }
                }
            }
        }
        return null;
    }

    private static function loadAllConfigs(): array
    {
        if (self::$brandConfigs !== null) {
            return self::$brandConfigs;
        }

        if (!app()->bound('db') || !app()->make('db')->getSchemaBuilder()->hasTable('brands')) {
            return [];
        }

        try {
            $brands = BrandModel::where('is_active', true)->get();
            self::$brandConfigs = [];
            foreach ($brands as $brand) {
                self::$brandConfigs[$brand->id] = $brand->toConfig();
            }
            return self::$brandConfigs;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function clearCache(): void
    {
        self::$brandConfigs = null;
    }
}
