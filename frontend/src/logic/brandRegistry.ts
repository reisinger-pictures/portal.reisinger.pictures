/**
 * Central brand resolution (frontend mirror of backend App\Support\BrandRegistry).
 *
 * The brand identifier is the short enum code (`rp` = B2B / reisinger.pictures,
 * `srp` = SRP / buy.reisinger.pictures). See features/infrastructure/12-brand-registry-and-settings-fixes.md.
 *
 * Pure functions only — no React, no side effects. UI-specific derivations (logos, portal name)
 * live in useBrand.ts and consume these primitives.
 */

export const BRAND_B2B = 'rp' as const;
export const BRAND_SRP = 'srp' as const;
export type Brand = typeof BRAND_B2B | typeof BRAND_SRP;

/**
 * Resolve the brand from a hostname. `buy.*` resolves to SRP, everything else to B2B.
 * Browsers resolve `*.localhost` to 127.0.0.1 automatically — no hosts file needed.
 */
export function getBrandFromHostname(hostname: string): Brand {
    if (hostname.toLowerCase().startsWith('buy.')) {
        return BRAND_SRP;
    }
    return BRAND_B2B;
}

export function isSrpBrand(brand: Brand): boolean {
    return brand === BRAND_SRP;
}

/** Setting/asset key prefix for a brand ('' for B2B, 'srp_' for SRP). */
export function brandPrefix(brand: Brand): 'srp_' | '' {
    return brand === BRAND_SRP ? 'srp_' : '';
}
