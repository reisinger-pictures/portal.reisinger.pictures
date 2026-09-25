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
    GalleryLicensingResult,
    GalleryPricingGroup,
    GalleryPricingSource,
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
    const usableTiers = config.tiers.length > 0 ? config.tiers : DEFAULT_VOLUME_PRICING.tiers;
    let qualifyingIndex = 0;
    for (let i = 0; i < usableTiers.length; i++) {
        if (count >= usableTiers[i].minQuantity) {
            qualifyingIndex = i;
        } else {
            break;
        }
    }

    const tier = usableTiers[qualifyingIndex];
    const basePriceCents = Math.max(0, usableTiers[0].priceCents);
    // Checkout clamps legacy/non-monotonic tier data to the base price. Mirror
    // that rule here so previews and totals can never exceed server pricing.
    const priceCents = Math.max(0, Math.min(basePriceCents, tier.priceCents));
    const price = (priceCents / 100).toFixed(0);
    const minQuantity = tier.minQuantity;
    const label = minQuantity === 0
        ? t`${price}€ pro Bild`
        : t`Ab ${minQuantity} Bildern ${price}€ pro Bild`;

    return {
        priceCents,
        tierIndex: qualifyingIndex,
        isMaxTier: qualifyingIndex === usableTiers.length - 1,
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

/** Map backend tiers into the config shape and mirror checkout's legacy clamp. */
export function tiersFromApi(tiers?: Array<{min_quantity: number; price_cents: number}>): VolumeTierConfig[] {
    if (!tiers || tiers.length === 0) {
        return DEFAULT_VOLUME_PRICING.tiers;
    }
    const mapped = [...tiers]
        .sort((a, b) => a.min_quantity - b.min_quantity)
        .map(tier => ({minQuantity: tier.min_quantity, priceCents: tier.price_cents}));
    const basePriceCents = Math.max(0, mapped[0].priceCents);
    return mapped.map(tier => ({
        ...tier,
        priceCents: Math.max(0, Math.min(basePriceCents, tier.priceCents)),
    }));
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

const isValidGalleryPricingSource = (source: GalleryPricingSource): boolean => (
    typeof source.galleryId === 'string'
    && source.galleryId.length > 0
    && Number.isSafeInteger(source.photoCount)
    && source.photoCount >= 0
);

/**
 * Group meta-gallery child galleries by the same effective server key used by
 * checkout. A group may contain more than one child gallery when they resolve
 * to the same mode and preset; it must not be split merely because the
 * galleries belong to different parent groups.
 */
export function groupGallerySourcesByPricing(
    sources: GalleryPricingSource[],
    resolveDescriptor: (source: GalleryPricingSource) => EffectivePricingDescriptor,
): GalleryPricingGroup[] {
    const groups = new Map<string, {descriptor: EffectivePricingDescriptor; sources: GalleryPricingSource[]}>();
    const seenGalleryIds = new Set<string>();

    for (const source of sources) {
        // Keep zero-count children: an empty volume gallery must still expose
        // its effective mode/preset to the meta-gallery management UI.
        if (
            !isValidGalleryPricingSource(source)
            || seenGalleryIds.has(source.galleryId)
        ) {
            continue;
        }
        seenGalleryIds.add(source.galleryId);

        const descriptor = resolveDescriptor(source);
        const key = groupKeyForDescriptor(descriptor);
        const current = groups.get(key);
        if (current) {
            current.sources.push(source);
        } else {
            groups.set(key, {descriptor, sources: [source]});
        }
    }

    return Array.from(groups.entries()).map(([key, group]) => {
        const galleryIds = Array.from(new Set(group.sources.map(source => source.galleryId)));
        const galleryGroupIds = Array.from(new Set(
            group.sources
                .map(source => source.galleryGroupId)
                .filter((id): id is string => typeof id === 'string' && id.length > 0),
        ));
        const photoCount = group.sources.reduce((sum, source) => sum + source.photoCount, 0);

        if (group.descriptor.licensingMode === 'scope_licensing') {
            return {
                key,
                licensingMode: group.descriptor.licensingMode,
                presetId: group.descriptor.presetId,
                presetName: group.descriptor.presetName,
                galleryIds,
                galleryGroupIds,
                photoCount,
                totalCents: null,
                pricePerItemCents: null,
                tiers: group.descriptor.config.tiers,
                tierIndex: 0,
                isMaxTier: false,
                nextTierCount: 0,
                nextTierLabel: '',
                isVolumePricing: false,
            };
        }

        const tier = calculateVolumeTier(photoCount, group.descriptor.config);
        let nextTierCount = 0;
        let nextTierLabel = '';
        if (!tier.isMaxTier) {
            const nextTier = group.descriptor.config.tiers[tier.tierIndex + 1];
            nextTierCount = Math.max(0, nextTier.minQuantity - photoCount);
            nextTierLabel = calculateVolumeTier(nextTier.minQuantity, group.descriptor.config).label;
        }

        return {
            key,
            licensingMode: group.descriptor.licensingMode,
            presetId: group.descriptor.presetId,
            presetName: group.descriptor.presetName,
            galleryIds,
            galleryGroupIds,
            photoCount,
            totalCents: photoCount * tier.priceCents,
            pricePerItemCents: tier.priceCents,
            tiers: group.descriptor.config.tiers,
            tierIndex: tier.tierIndex,
            isMaxTier: tier.isMaxTier,
            nextTierCount,
            nextTierLabel,
            isVolumePricing: true,
        };
    });
}

export function sumGalleryPricingGroupTotals(groups: GalleryPricingGroup[]): number {
    return groups.reduce((sum, group) => sum + (group.totalCents ?? 0), 0);
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

const asTermsMap = (value: unknown, expectedGalleryIds: string[]): Record<string, EffectiveLicenseTerms> | null => {
    if (!isRecord(value)) return null;
    if (!expectedGalleryIds.every(id => isRecord(value[id]))) return null;
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

interface ResolvedGalleryLicenseTerms {
    globalTerms: EffectiveLicenseTerms | undefined;
    galleryTermsMap: Record<string, EffectiveLicenseTerms> | null;
    singleTerms: EffectiveLicenseTerms | null;
    isLoading: boolean;
    descriptorForGallery: (galleryId?: string) => EffectivePricingDescriptor;
}

/**
 * Resolve all requested child-gallery terms through one stable SWR key. A
 * single gallery keeps the ordinary endpoint response; a mixed set uses one
 * composite fetcher which performs the per-gallery requests concurrently.
 */
function useResolvedGalleryLicenseTerms(galleryIds: string[]): ResolvedGalleryLicenseTerms {
    const {terms, isLoading: globalTermsLoading} = useLicenseTerms();
    const uniqueGalleryIds = Array.from(new Set(
        galleryIds.filter(id => typeof id === 'string' && id.length > 0),
    )).sort();
    const usesCompositeTerms = uniqueGalleryIds.length > 1;
    const termsKey = usesCompositeTerms
        ? cartTermsKey(uniqueGalleryIds)
        : uniqueGalleryIds.length === 1
            ? `/api/settings/license-terms?gallery_id=${encodeURIComponent(uniqueGalleryIds[0])}`
            : null;
    const {data, isLoading} = useSWR<
        EffectiveLicenseTerms | Record<string, EffectiveLicenseTerms>
    >(termsKey, fetchCartLicenseTerms, {revalidateOnFocus: false});

    const globalTerms = terms as LicenseTerms | undefined;
    const galleryTermsMap = usesCompositeTerms
        ? asTermsMap(data, uniqueGalleryIds)
        : null;
    // A composite response is either a complete per-gallery map or no child
    // terms at all. Never reinterpret a partial map as a global terms object.
    const singleTerms = usesCompositeTerms ? null : asTerms(data);

    return {
        globalTerms,
        galleryTermsMap,
        singleTerms,
        isLoading: Boolean(globalTermsLoading || isLoading),
        descriptorForGallery: (galleryId?: string) => descriptorFromTerms(
            (galleryId ? galleryTermsMap?.[galleryId] : undefined)
            ?? singleTerms
            ?? globalTerms,
        ),
    };
}

/**
 * Resolve a meta-gallery's child galleries independently. The returned groups
 * are keyed by effective mode/preset, matching the server's grouping rule;
 * gallery/group IDs remain available for a transparent UI breakdown.
 */
export function useGalleryLicensing(sources: GalleryPricingSource[]): GalleryLicensingResult {
    const validSources = sources.filter(isValidGalleryPricingSource);
    const galleryIds = validSources.map(source => source.galleryId);
    const resolvedTerms = useResolvedGalleryLicenseTerms(galleryIds);
    const groups = groupGallerySourcesByPricing(
        validSources,
        source => resolvedTerms.descriptorForGallery(source.galleryId),
    );
    const volumeSubtotalCents = sumGalleryPricingGroupTotals(
        groups.filter(group => group.isVolumePricing),
    );

    return {
        isVolumePricing: groups.some(group => group.isVolumePricing),
        groups,
        volumeSubtotalCents,
        isLoading: resolvedTerms.isLoading,
    };
}

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
    const cartGalleryIds = items
        .map(item => item.galleryId)
        .filter((id): id is string => typeof id === 'string' && id.length > 0);
    // An explicit displayed-gallery context still resolves every cart child
    // independently. The displayed descriptor is used only for the
    // prospective summary below; the groups remain authoritative for a mixed
    // cart and therefore cannot leak the displayed gallery into another one.
    const requestedGalleryIds = galleryId
        ? [...cartGalleryIds, galleryId]
        : cartGalleryIds;
    const resolvedTerms = useResolvedGalleryLicenseTerms(requestedGalleryIds);
    const displayedDescriptor = resolvedTerms.descriptorForGallery(galleryId);

    // Resolve every cart item independently. A complete composite response
    // supplies each child descriptor; a missing/legacy composite response falls
    // back to the brand terms. Never apply the displayed gallery's descriptor
    // to unrelated cart galleries.
    const groups = groupCartItemsByPricing(
        items,
        item => resolvedTerms.descriptorForGallery(item.galleryId),
    );
    const volumeGroups = groups.filter(group => group.isVolumePricing);

    // A photo-price card may show a prospective price for a gallery that is
    // not in the cart yet. Count only cart items from the same effective
    // server group; items from another gallery/preset must not advance this
    // gallery's retroactive tier.
    const displayedGroupKey = galleryId ? groupKeyForDescriptor(displayedDescriptor) : null;
    const displayedPricingItems = displayedGroupKey === null
        ? []
        : items.filter(item => (
            item.galleryId
            && groupKeyForDescriptor(resolvedTerms.descriptorForGallery(item.galleryId)) === displayedGroupKey
        ));
    const displayedGroup = galleryId && displayedDescriptor.licensingMode === 'volume_licensing'
        ? createPricingGroup(
            `displayed|${displayedGroupKey}`,
            displayedDescriptor,
            displayedPricingItems,
        )
        : undefined;
    const selectedGroup = galleryId ? displayedGroup : volumeGroups[0];
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
        isVolumePricing: galleryId
            ? displayedDescriptor.licensingMode === 'volume_licensing'
            : selectedGroup !== undefined,
        groups,
        groupedTotalCents,
        volumeSubtotalCents,
        volumeItemPrices,
    };
}
