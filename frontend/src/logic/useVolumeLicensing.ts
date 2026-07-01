/**
 * Volume Licensing Pricing Logic.
 *
 * Provides pure functions for volume tier calculation and a React hook
 * that derives the current volume licensing state from cart items.
 *
 * The default configuration (30/25/20 €, thresholds 10/20) is hardcoded
 * here but is designed to be replaceable with a future settings/API source.
 */
import {CartItem, VolumeLicensingResult} from './CartContext';
import {useBrand} from './useBrand';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface VolumePricingConfig {
    /** Items < tier2Threshold → tier 1 price (default: 0–9 items). */
    tier1Threshold: number;
    /** Items >= tier2Threshold but < tier3Threshold → tier 2 price (default: 10–19). */
    tier2Threshold: number;
    /** Items >= tier3Threshold → tier 3 price (default: 20+). */
    tier3Threshold: number;
    /** Tier 1 price in cents (default: 3000 = 30 €). */
    tier1PriceCents: number;
    /** Tier 2 price in cents (default: 2500 = 25 €). */
    tier2PriceCents: number;
    /** Tier 3 price in cents (default: 2000 = 20 €). */
    tier3PriceCents: number;
}

export const DEFAULT_VOLUME_PRICING: VolumePricingConfig = {
    tier1Threshold: 0,
    tier2Threshold: 10,
    tier3Threshold: 20,
    tier1PriceCents: 3000,
    tier2PriceCents: 2500,
    tier3PriceCents: 2000,
};

// ---------------------------------------------------------------------------
// Pure functions
// ---------------------------------------------------------------------------

export interface VolumeTierResult {
    priceCents: number;
    tier: 1 | 2 | 3;
    label: string;
}

/**
 * Determine the volume tier for a given item count.
 *
 * @param count – number of items in the cart
 * @param config – optional override; defaults to `DEFAULT_VOLUME_PRICING`
 */
export function calculateVolumeTier(
    count: number,
    config: VolumePricingConfig = DEFAULT_VOLUME_PRICING,
): VolumeTierResult {
    if (count >= config.tier3Threshold) {
        return {priceCents: config.tier3PriceCents, tier: 3, label: `Ab ${config.tier3Threshold} Bildern ${(config.tier3PriceCents / 100).toFixed(0)}€ pro Bild`};
    }
    if (count >= config.tier2Threshold) {
        return {priceCents: config.tier2PriceCents, tier: 2, label: `Ab ${config.tier2Threshold} Bildern ${(config.tier2PriceCents / 100).toFixed(0)}€ pro Bild`};
    }
    return {priceCents: config.tier1PriceCents, tier: 1, label: `${(config.tier1PriceCents / 100).toFixed(0)}€ pro Bild`};
}

/**
 * Calculate the total price for all items using retroactive volume pricing.
 *
 * Every item is priced at the tier determined by the *total* item count,
 * not at the per-item historic price.
 *
 * @param items – all cart items
 * @param config – optional volume pricing config
 */
export function calculateVolumeTotal(
    items: Array<{priceCents?: number; price?: number}>,
    config: VolumePricingConfig = DEFAULT_VOLUME_PRICING,
): number {
    const count = items.length;
    const {priceCents} = calculateVolumeTier(count, config);
    // Quote items are excluded but they still count towards the volume tier.
    // The price field for quote items is 0 — we just sum non-quote items
    // at the volume price. However retroactive pricing means ALL items
    // at the same price. So: count * priceCents.
    return count * priceCents;
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/**
 * React hook that derives volume licensing pricing from cart items.
 *
 * Returns `isVolumePricing: false` when the current licensing mode is not volume licensing,
 * so consumers can branch on this flag.
 */
export function useVolumeLicensing(items: CartItem[]): VolumeLicensingResult {
    const {isSrp} = useBrand();
    const config = DEFAULT_VOLUME_PRICING;

    if (!isSrp) {
        return {
            tier: 1,
            pricePerItemCents: config.tier1PriceCents,
            totalCents: 0,
            nextTierCount: 0,
            nextTierLabel: '',
            isVolumePricing: false,
        };
    }

    const count = items.length;
    const {priceCents, tier} = calculateVolumeTier(count, config);
    const totalCents = count * priceCents;

    // Determine items needed for the next tier
    let nextTierCount = 0;
    let nextTierLabel = '';
    if (tier === 1) {
        nextTierCount = config.tier2Threshold - count;
        nextTierLabel = `Ab ${config.tier2Threshold} Bildern ${(config.tier2PriceCents / 100).toFixed(0)}€ pro Bild`;
    } else if (tier === 2) {
        nextTierCount = config.tier3Threshold - count;
        nextTierLabel = `Ab ${config.tier3Threshold} Bildern ${(config.tier3PriceCents / 100).toFixed(0)}€ pro Bild`;
    }

    return {
        tier,
        pricePerItemCents: priceCents,
        totalCents,
        nextTierCount,
        nextTierLabel,
        isVolumePricing: true,
    };
}
