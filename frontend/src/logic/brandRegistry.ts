/**
 * Central brand resolution (frontend mirror of backend App\Support\BrandRegistry).
 *
 * The brand identifier is the short enum code (`rp` = B2B / reisinger.pictures,
 * `srp` = SRP / story.reisinger.pictures). See features/infrastructure/12-brand-registry-and-settings-fixes.md.
 *
 * Pure functions only — no React, no side effects. UI-specific derivations (logos, portal name)
 * live in useBrand.ts and consume these primitives.
 */

export const BRAND_B2B = 'rp' as const;
export const BRAND_SRP = 'srp' as const;
export type Brand = typeof BRAND_B2B | typeof BRAND_SRP;

/** Local dev host for the SRP brand (Vite SRP proxy target). */
export const SRP_DEV_HOST = 'portal-srp.test';
/** Production domain identifying the SRP brand. */
export const SRP_PROD_DOMAIN = 'story.reisinger.pictures';

/**
 * Resolve the brand from a hostname. Production uses the domain, local dev uses the
 * `portal-srp.test` host (the SRP Vite proxy target). Everything else is B2B.
 */
export function getBrandFromHostname(hostname: string): Brand {
    if (hostname.includes(SRP_PROD_DOMAIN) || hostname === SRP_DEV_HOST) {
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
