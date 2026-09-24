/**
 * Volume licensing pricing logic.
 *
 * Pricing is retroactive inside one effective `(licensing mode, preset)`
 * group. The server uses the same grouping for mixed carts, so the frontend
 * must not apply the first gallery's mode or preset to every item.
 */
import {t} from '@lingui/core/macro';
import useSWR from 'swr';
import {fetcher} from '../api';
import {
    CartItem,
    CartLicensingMode,
    CartPricingGroup,
    VolumeLicensingResult,
    VolumeTierConfig,
} from './CartContext';
import {LicenseTerms, useLicenseTerms} from './useLicenseTerms';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface VolumePricingConfig {
    /** Tiers ordered by `minQuantity` ascending. First tier must start at 0. */
    tiers: VolumeTierConfig[];
}

/** Backend shape of `volume_pricing` inside `/api/settings/license-terms`. */
export interface VolumePricingPayload {
    preset_id?: string | null;
    preset_name?: string | null;
    tiers?: Array<{min_quantity: number; price_cents: number}> | null;
}

export const DEFAULT_VOLUME_PRICING: VolumePricingConfig = {
    tiers: [
        {minQuantity: 0, priceCents: 3000},
        {minQuantity: 10, priceCents: 2500},
        {minQuantity: 20, priceCents: 2000},
    ],
};

/** The fields returned by the public license-terms endpoint. */
export interface EffectiveLicenseTerms {
    pricing_strategy?: string | null;
    volume_pricing?: VolumePricingPayload | null;
}

export interface EffectivePricingDescriptor {
    licensingMode: CartLicensingMode;
    presetId: string;
    presetName: string | null;
    config: VolumePricingConfig;
}

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
 * Resolve the effective mode and preset from a license-terms response.
 * A missing/loading response intentionally falls back to scope licensing,
 * which is the server's safe default.
 */
export function descriptorFromTerms(terms?: EffectiveLicenseTerms | null): EffectivePricingDescriptor {
    const licensingMode: CartLicensingMode = terms?.pricing_strategy === 'volume_licensing'
        ? 'volume_licensing'
        : 'scope_licensing';
    const volumePricing = terms?.volume_pricing ?? null;
    const presetId = licensingMode === 'volume_licensing'
        ? (volumePricing?.preset_id?.trim() || 'default')
        : 'default';
    const presetName = licensingMode === 'volume_licensing'
        ? (volumePricing?.preset_name?.trim() || null)
        : null;

    return {
        licensingMode,
        presetId,
        presetName,
        config: {tiers: tiersFromApi(volumePricing?.tiers ?? undefined)},
    };
}

const groupKeyForDescriptor = (descriptor: EffectivePricingDescriptor): string => (
    `${descriptor.licensingMode}|${descriptor.presetId}`
);

const createPricingGroup = (
    key: string,
    descriptor: EffectivePricingDescriptor,
    items: CartItem[],
): CartPricingGroup => {
    const nonQuoteItems = items.filter(item => !item.isQuote);
    const itemIds = items.map(item => item.photoId);

    if (descriptor.licensingMode === 'scope_licensing') {
        return {
            key,
            licensingMode: descriptor.licensingMode,
            presetId: descriptor.presetId,
            presetName: descriptor.presetName,
            items,
            itemIds,
            totalCents: nonQuoteItems.reduce((sum, item) => sum + item.price, 0),
            pricePerItemCents: null,
            tiers: descriptor.config.tiers,
            tierIndex: 0,
            isMaxTier: false,
            nextTierCount: 0,
            nextTierLabel: '',
            isVolumePricing: false,
            itemPriceCents: {},
        };
    }

    const count = nonQuoteItems.length;
    const tier = calculateVolumeTier(count, descriptor.config);
    const itemPriceCents = Object.fromEntries(
        nonQuoteItems.map(item => [item.photoId, tier.priceCents]),
    );
    let nextTierCount = 0;
    let nextTierLabel = '';
    if (!tier.isMaxTier) {
        const nextTier = descriptor.config.tiers[tier.tierIndex + 1];
        nextTierCount = Math.max(0, nextTier.minQuantity - count);
        nextTierLabel = calculateVolumeTier(nextTier.minQuantity, descriptor.config).label;
    }

    return {
        key,
        licensingMode: descriptor.licensingMode,
        presetId: descriptor.presetId,
        presetName: descriptor.presetName,
        items,
        itemIds,
        totalCents: count * tier.priceCents,
        pricePerItemCents: tier.priceCents,
        tiers: descriptor.config.tiers,
        tierIndex: tier.tierIndex,
        isMaxTier: tier.isMaxTier,
        nextTierCount,
        nextTierLabel,
        isVolumePricing: true,
        itemPriceCents,
    };
};

/**
 * Group items using the same key as the server: effective mode plus the
 * effective volume preset. The resolver is intentionally synchronous so the
 * grouping can be unit-tested without React or a network request.
 */
export function groupCartItemsByPricing(
    items: CartItem[],
    resolveDescriptor: (item: CartItem) => EffectivePricingDescriptor,
): CartPricingGroup[] {
    const groups = new Map<string, {descriptor: EffectivePricingDescriptor; items: CartItem[]}>();

    for (const item of items) {
        const descriptor = resolveDescriptor(item);
        const key = groupKeyForDescriptor(descriptor);
        const current = groups.get(key);
        if (current) {
            current.items.push(item);
        } else {
            groups.set(key, {descriptor, items: [item]});
        }
    }

    return Array.from(groups.entries()).map(([key, group]) => (
        createPricingGroup(key, group.descriptor, group.items)
    ));
}

export function sumPricingGroupTotals(groups: CartPricingGroup[]): number {
    return groups.reduce((sum, group) => sum + group.totalCents, 0);
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

const CART_TERMS_PREFIX = '__cart_license_terms__:';

const cartTermsKey = (galleryIds: string[]): string => (
    `${CART_TERMS_PREFIX}${encodeURIComponent(JSON.stringify(galleryIds))}`
);

const isRecord = (value: unknown): value is Record<string, unknown> => (
    typeof value === 'object' && value !== null && !Array.isArray(value)
);

const asTermsMap = (value: unknown): Record<string, EffectiveLicenseTerms> | null => {
    if (!isRecord(value)) return null;
    const entries = Object.entries(value);
    if (!entries.every(([, entry]) => isRecord(entry))) return null;
    return value as Record<string, EffectiveLicenseTerms>;
};

const asTerms = (value: unknown): EffectiveLicenseTerms | null => (
    isRecord(value) ? value as EffectiveLicenseTerms : null
);

const parseGalleryIds = (key: string): string[] => {
    try {
        const parsed: unknown = JSON.parse(decodeURIComponent(key.slice(CART_TERMS_PREFIX.length)));
        if (!Array.isArray(parsed) || !parsed.every((id): id is string => typeof id === 'string')) {
            return [];
        }
        return parsed;
    } catch {
        return [];
    }
};

/**
 * Load a single gallery's terms or all terms needed by a mixed cart. The
 * latter is one SWR request so hook order remains stable as the cart changes.
 */
const fetchCartLicenseTerms = async (
    key: string,
): Promise<EffectiveLicenseTerms | Record<string, EffectiveLicenseTerms>> => {
    if (!key.startsWith(CART_TERMS_PREFIX)) {
        return fetcher<EffectiveLicenseTerms>(key);
    }

    const galleryIds = parseGalleryIds(key);
    const entries = await Promise.all(galleryIds.map(async (galleryId) => {
        const terms = await fetcher<EffectiveLicenseTerms>(
            `/api/settings/license-terms?gallery_id=${encodeURIComponent(galleryId)}`,
        );
        return [galleryId, terms] as const;
    }));
    return Object.fromEntries(entries);
};

/**
 * React hook that derives volume licensing pricing from cart items.
 *
 * `galleryId` is the gallery currently displayed by the caller. Supplying it
 * takes precedence over the first cart item's gallery, which is important when
 * a shopper opens a photo from another gallery while the cart is not empty.
 * Callers without displayed-gallery context resolve every cart gallery and
 * group the resulting server-equivalent pricing groups.
 */
export function useVolumeLicensing(items: CartItem[], galleryId?: string): VolumeLicensingResult {
    const {terms} = useLicenseTerms();
    const cartGalleryIds = Array.from(new Set(
        items
            .map(item => item.galleryId)
            .filter((id): id is string => typeof id === 'string' && id.length > 0),
    ));
    const usesCompositeTerms = !galleryId && cartGalleryIds.length > 1;
    const singleGalleryId = galleryId ?? (cartGalleryIds.length === 1 ? cartGalleryIds[0] : undefined);
    const termsKey = usesCompositeTerms
        ? cartTermsKey(cartGalleryIds)
        : singleGalleryId
            ? `/api/settings/license-terms?gallery_id=${encodeURIComponent(singleGalleryId)}`
            : null;
    const {data: termsData} = useSWR<
        EffectiveLicenseTerms | Record<string, EffectiveLicenseTerms>
    >(termsKey, fetchCartLicenseTerms, {revalidateOnFocus: false});

    const globalTerms = terms as LicenseTerms | undefined;
    const galleryTerms = asTerms(termsData);
    // A single-gallery response has the same object shape as a normal terms
    // response. Only interpret it as a map when the composite key requested
    // one, otherwise a normal `pricing_strategy` field would be mistaken for a
    // gallery id.
    const galleryTermsMap = usesCompositeTerms ? asTermsMap(termsData) : null;
    const displayedDescriptor = galleryId
        ? descriptorFromTerms(
            (galleryTermsMap?.[galleryId] ?? galleryTerms ?? globalTerms) as EffectiveLicenseTerms | undefined,
        )
        : galleryTermsMap
            ? descriptorFromTerms(globalTerms as EffectiveLicenseTerms | undefined)
            : descriptorFromTerms((galleryTerms ?? globalTerms) as EffectiveLicenseTerms | undefined);

    const groups = groupCartItemsByPricing(items, (item) => {
        // Explicit gallery context is used by the photo-price card. It keeps
        // the existing prospective-price behavior for that displayed gallery.
        if (galleryId) return displayedDescriptor;
        if (galleryTermsMap) {
            const itemTerms = item.galleryId ? galleryTermsMap[item.galleryId] : undefined;
            return descriptorFromTerms((itemTerms ?? globalTerms) as EffectiveLicenseTerms | undefined);
        }
        return displayedDescriptor;
    });
    const volumeGroups = groups.filter(group => group.isVolumePricing);
    const selectedGroup = volumeGroups[0];
    const volumeItemPrices = Object.assign(
        {},
        ...volumeGroups.map(group => group.itemPriceCents),
    );
    const volumeSubtotalCents = volumeGroups.reduce((sum, group) => sum + group.totalCents, 0);
    const groupedTotalCents = sumPricingGroupTotals(groups);
    const selectedTiers = selectedGroup?.tiers ?? displayedDescriptor.config.tiers;

    return {
        tierIndex: selectedGroup?.tierIndex ?? 0,
        isMaxTier: selectedGroup?.isMaxTier ?? false,
        pricePerItemCents: selectedGroup?.pricePerItemCents ?? 0,
        totalCents: selectedGroup?.totalCents ?? 0,
        nextTierCount: selectedGroup?.nextTierCount ?? 0,
        nextTierLabel: selectedGroup?.nextTierLabel ?? '',
        tiers: selectedTiers,
        isVolumePricing: selectedGroup !== undefined,
        groups,
        groupedTotalCents,
        volumeSubtotalCents,
        volumeItemPrices,
    };
}
