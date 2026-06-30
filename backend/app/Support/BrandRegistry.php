<?php

namespace App\Support;

use App\Enums\Brand;
use App\Models\Order;

/**
 * Central authority for brand resolution and `config('app.brand')` access.
 *
 * The brand identifier is the short enum code (`Brand::B2B->value` = 'rp',
 * `Brand::SRP->value` = 'srp'); `null` means explicitly cross-brand. All Host→brand mapping,
 * prefix logic, and persisted-brand reconstruction MUST go through this class so there is a
 * single source of truth (see features/infrastructure/12-brand-registry-and-settings-fixes.md).
 */
class BrandRegistry
{
    /** Local dev host that maps to the SRP brand (Vite SRP proxy target). */
    public const SRP_DEV_HOST = 'portal-srp.test';

    /**
     * Resolve the brand from an HTTP host (production domain or local dev host).
     */
    public static function fromHost(string $host): Brand
    {
        if (str_contains($host, Brand::SRP->domain()) || $host === self::SRP_DEV_HOST) {
            return Brand::SRP;
        }
        return Brand::B2B;
    }

    /**
     * Current brand from config, or null if unset (CLI/queue context before reconstruction).
     */
    public static function current(): ?Brand
    {
        return Brand::tryFrom((string) config('app.brand'));
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
     * Persist the brand into runtime config (`config('app.brand')`).
     * Pass null to clear (rare; mainly for tests).
     */
    public static function set(?Brand $brand): void
    {
        config(['app.brand' => $brand?->value]);
    }

    /**
     * Reconstruct the brand from a persisted order. Falls back to B2B when the order has no
     * brand (legacy rows), guaranteeing a deterministic, non-empty result for rendering.
     */
    public static function resolveFromOrder(Order $order): Brand
    {
        return $order->brand ?? Brand::B2B;
    }
}
