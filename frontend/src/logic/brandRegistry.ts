/**
 * Central brand resolution (frontend mirror of backend App\Support\BrandRegistry).
 *
 * The brand identifier is the short enum code (`rp` = B2B / reisinger.pictures,
 * `atr` = ATR / all-the.rest). See features/infrastructure/12-brand-registry-and-settings-fixes.md.
 *
 * Pure functions only — no React, no side effects. UI-specific derivations (logos, portal name)
 * live in useBrand.ts and consume these primitives.
 */

export const BRAND_B2B = 'rp' as const;
export const BRAND_ATR = 'atr' as const;
export type Brand = typeof BRAND_B2B | typeof BRAND_ATR;

/** Local dev host for the ATR brand (Vite ATR proxy target). */
export const ATR_DEV_HOST = 'portal-atr.test';
/** Production domain identifying the ATR brand. */
export const ATR_PROD_DOMAIN = 'all-the.rest';

/**
 * Resolve the brand from a hostname. Production uses the domain, local dev uses the
 * `portal-atr.test` host (the ATR Vite proxy target). Everything else is B2B.
 */
export function getBrandFromHostname(hostname: string): Brand {
    if (hostname.includes(ATR_PROD_DOMAIN) || hostname === ATR_DEV_HOST) {
        return BRAND_ATR;
    }
    return BRAND_B2B;
}

export function isAtrBrand(brand: Brand): boolean {
    return brand === BRAND_ATR;
}

/** Setting/asset key prefix for a brand ('' for B2B, 'atr_' for ATR). */
export function brandPrefix(brand: Brand): 'atr_' | '' {
    return brand === BRAND_ATR ? 'atr_' : '';
}
