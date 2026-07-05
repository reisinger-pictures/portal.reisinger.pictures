<?php

namespace App\Support;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\Order;

/**
 * Central authority for brand resolution and container-scoped brand access.
 *
 * The brand identifier is the short enum code (`Brand::B2B->value` = 'rp',
 * `Brand::SRP->value` = 'srp'); `null` means explicitly cross-brand. All Host→brand mapping,
 * prefix logic, and persisted-brand reconstruction MUST go through this class so there is a
 * single source of truth (see features/infrastructure/12-brand-registry-and-settings-fixes.md).
 *
 * Brand state is stored in the container as a scoped singleton (`brand.context`)
 * instead of global `config()`. This avoids cross-request and cross-job leakage
 * because the binding is re-bound per request via BrandContextMiddleware.
 */
class BrandRegistry
{
    private const CONTAINER_KEY = 'brand.context';

    /**
     * Resolve the brand from an HTTP host.
     *
     * `portal.localhost` → B2B, `buy.localhost` → SRP.
     */
    public static function fromHost(string $host): Brand
    {
        if (str_starts_with($host, 'buy.')) {
            return Brand::SRP;
        }
        return Brand::B2B;
    }

    /**
     * Current brand from the container, or null if unset (CLI/queue context before reconstruction).
     */
    public static function current(): ?Brand
    {
        if (!app()->bound(self::CONTAINER_KEY)) {
            return null;
        }
        $value = app(self::CONTAINER_KEY);
        return $value instanceof Brand ? $value : null;
    }

    /**
     * Current brand, falling back to B2B when unset (safe default for rendering/CLI).
     */
    public static function currentOrDefault(): Brand
    {
        return self::current() ?? Brand::B2B;
    }

    public static function isSrp(): bool
    {
        return self::current() === Brand::SRP;
    }

    /**
     * Setting/asset key prefix for the current brand ('' for B2B, 'srp_' for SRP).
     */
    public static function prefix(): string
    {
        return self::currentOrDefault()->prefix();
    }

    /**
     * Persist the brand into the container as a scoped singleton.
     * Pass null to clear (rare; mainly for tests).
     */
    public static function set(?Brand $brand): void
    {
        if ($brand === null) {
            app()->offsetUnset(self::CONTAINER_KEY);
        } else {
            app()->instance(self::CONTAINER_KEY, $brand);
        }
    }

    /**
     * Reconstruct the brand from a persisted order. Falls back to B2B when the order has no
     * brand (legacy rows), guaranteeing a deterministic, non-empty result for rendering.
     */
    public static function resolveFromOrder(Order $order): Brand
    {
        return $order->brand ?? Brand::B2B;
    }

    /**
     * Reconstruct the brand from a persisted contract. Falls back to B2B when the contract has no
     * brand (legacy rows), guaranteeing a deterministic, non-empty result for rendering.
     */
    public static function resolveFromContract(Contract $contract): Brand
    {
        return $contract->brand ?? Brand::B2B;
    }

    /**
     * Resolve the frontend URL for the given brand (or current brand by default).
     */
    public static function frontendUrl(?Brand $brand = null): string
    {
        $brand ??= self::currentOrDefault();
        if ($brand === Brand::SRP) {
            return rtrim(config('app.frontend_url_srp', config('app.frontend_url', config('app.url'))), '/');
        }
        return rtrim(config('app.frontend_url', config('app.url')), '/');
    }

    /**
     * Reset the runtime brand to null.
     *
     * Queue workers (php artisan queue:work) are long-lived — container state persists across
     * jobs. Call reset() before/after each job to prevent stale brand state from leaking between
     * jobs. The reset is wired via Queue::before() in AppServiceProvider::boot().
     */
    public static function reset(): void
    {
        self::set(null);
    }
}
