/**
 * Volume Licensing Pricing Logic.
 *
 * Provides pure functions for volume tier calculation and a React hook
 * that derives the current volume licensing state from cart items.
 *
 * Pricing is retroactive: all items of a cart are charged at the tier price
 * determined by the *total* count of non-quote items. The tier structure is
 * fully configurable (arbitrary number of tiers) and comes from the backend
 * `/api/settings/license-terms` response (`volume_pricing.tiers`). While the
 * terms are loading, `DEFAULT_VOLUME_PRICING` is used as a stable fallback.
 */
import {t} from "@lingui/core/macro";
import useSWR from 'swr';
import {fetcher} from '../api';
import {CartItem, VolumeLicensingResult, VolumeTierConfig} from './CartContext';
import {useLicensingMode} from './useLicensingMode';
import {useLicenseTerms, LicenseTerms} from './useLicenseTerms';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface VolumePricingConfig {
    /** Tiers ordered by `minQuantity` ascending. First tier must start at 0. */
    tiers: VolumeTierConfig[];
}

/** Backend shape of `volume_pricing` inside `/api/settings/license-terms`. */
export interface VolumePricingPayload {
    preset_id?: string;
    preset_name?: string;
    tiers: Array<{min_quantity: number; price_cents: number}>;
}

export const DEFAULT_VOLUME_PRICING: VolumePricingConfig = {
    tiers: [
        {minQuantity: 0, priceCents: 3000},
        {minQuantity: 10, priceCents: 2500},
        {minQuantity: 20, priceCents: 2000},
    ],
};

// ---------------------------------------------------------------------------
// Pure functions
// ---------------------------------------------------------------------------

export interface VolumeTierResult {
    priceCents: number;
    /** 0-based index of the qualifying tier within the config. */
    tierIndex: number;
    /** The qualifying tier is the last (cheapest) one → no further discount. */
    isMaxTier: boolean;
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
    let qualifyingIndex = 0;
    for (let i = 0; i < config.tiers.length; i++) {
        if (count >= config.tiers[i].minQuantity) {
            qualifyingIndex = i;
        } else {
            break;
        }
    }

    const tier = config.tiers[qualifyingIndex];
    const price = (tier.priceCents / 100).toFixed(0);
    const minQuantity = tier.minQuantity;
    const label = minQuantity === 0
        ? t`${price}€ pro Bild`
        : t`Ab ${minQuantity} Bildern ${price}€ pro Bild`;

    return {
        priceCents: tier.priceCents,
        tierIndex: qualifyingIndex,
        isMaxTier: qualifyingIndex === config.tiers.length - 1,
        label,
    };
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
    items: Array<{priceCents?: number; price?: number; isQuote?: boolean}>,
    config: VolumePricingConfig = DEFAULT_VOLUME_PRICING,
): number {
    const nonQuoteItems = items.filter(i => !i.isQuote);
    const count = nonQuoteItems.length;
    const {priceCents} = calculateVolumeTier(count, config);
    return count * priceCents;
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/** Map the backend `volume_pricing.tiers` payload into the config shape. */
export function tiersFromApi(tiers?: Array<{min_quantity: number; price_cents: number}>): VolumeTierConfig[] {
    if (!tiers || tiers.length === 0) {
        return DEFAULT_VOLUME_PRICING.tiers;
    }
    return [...tiers]
        .sort((a, b) => a.min_quantity - b.min_quantity)
        .map(t => ({minQuantity: t.min_quantity, priceCents: t.price_cents}));
}

/**
 * React hook that derives volume licensing pricing from cart items.
 *
 * Returns `isVolumePricing: false` when the effective licensing mode is not
 * volume licensing, so consumers can branch on this flag.
 */
export function useVolumeLicensing(items: CartItem[]): VolumeLicensingResult {
    const galleryId = items.find(i => !i.isQuote && i.galleryId)?.galleryId;
    const licensingMode = useLicensingMode(galleryId);

    const { terms } = useLicenseTerms();
    const galleryKey = galleryId ? `/api/settings/license-terms?gallery_id=${galleryId}` : null;
    const { data: galleryTerms } = useSWR<LicenseTerms>(galleryKey, fetcher, {revalidateOnFocus: false});

    const effectiveTerms = galleryId && galleryTerms ? galleryTerms : terms;
    interface TermsWithVolumePricing { volume_pricing?: VolumePricingPayload | null }
    const volumePricing = (effectiveTerms as unknown as TermsWithVolumePricing | undefined)?.volume_pricing;
    const config: VolumePricingConfig = {
        tiers: tiersFromApi(volumePricing?.tiers),
    };

    if (licensingMode !== 'volume_licensing') {
        return {
            tierIndex: 0,
            isMaxTier: config.tiers.length === 1,
            pricePerItemCents: config.tiers[0].priceCents,
            totalCents: 0,
            nextTierCount: 0,
            nextTierLabel: '',
            tiers: config.tiers,
            isVolumePricing: false,
        };
    }

    const nonQuoteItems = items.filter(i => !i.isQuote);
    const count = nonQuoteItems.length;
    const {priceCents, tierIndex, isMaxTier} = calculateVolumeTier(count, config);
    const totalCents = count * priceCents;

    // Determine items needed for the next tier
    let nextTierCount = 0;
    let nextTierLabel = '';
    if (!isMaxTier) {
        const nextTier = config.tiers[tierIndex + 1];
        nextTierCount = nextTier.minQuantity - count;
        nextTierLabel = calculateVolumeTier(nextTier.minQuantity, config).label;
    }

    return {
        tierIndex,
        isMaxTier,
        pricePerItemCents: priceCents,
        totalCents,
        nextTierCount,
        nextTierLabel,
        tiers: config.tiers,
        isVolumePricing: true,
    };
}
