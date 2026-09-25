import { createContext, useContext } from 'react';
import { ResolutionTier } from './pricingLogic';

export interface CartItem {
    photoId: string;
    filename?: string;
    thumb_url?: string;
    tier: ResolutionTier;
    galleryId?: string;
    /** Parent gallery group (meta-gallery) used for coupon scope validation. */
    galleryGroupId?: string;
    useCaseId?: string;
    useCaseName?: string;
    modifierIds?: string[];
    modifierNames?: string[];
    isQuote?: boolean;
    notes?: string;
    price: number;
}

export interface VolumeTierConfig {
    /** From this many items (inclusive) the tier applies. */
    minQuantity: number;
    /** Unit price in cents for the tier. */
    priceCents: number;
}

export type CartLicensingMode = 'scope_licensing' | 'volume_licensing';

/**
 * One server-equivalent cart pricing group.
 *
 * The backend groups mixed carts by the effective licensing mode and, for
 * volume carts, by the effective preset. Keeping the grouping in the client
 * result lets the cart render and total each group with the same inputs as
 * checkout instead of applying the first gallery's configuration globally.
 */
export interface CartPricingGroup {
    key: string;
    licensingMode: CartLicensingMode;
    presetId: string;
    presetName: string | null;
    items: CartItem[];
    itemIds: string[];
    totalCents: number;
    /** Null for scope groups, which retain each item's server-priced value. */
    pricePerItemCents: number | null;
    tiers: VolumeTierConfig[];
    tierIndex: number;
    isMaxTier: boolean;
    nextTierCount: number;
    nextTierLabel: string;
    isVolumePricing: boolean;
    /** Actual server-consistent unit prices for volume items. */
    itemPriceCents: Record<string, number>;
}

/**
 * One child gallery represented in a meta-gallery pricing preview.
 * `photoCount` is the complete authorized gallery count returned by the
 * meta-gallery endpoint. Legacy responses may derive it from loaded photos;
 * zero is valid for an empty child gallery, which still contributes its
 * effective licensing descriptor. The same child can therefore be grouped with
 * another gallery only when the effective server pricing key matches.
 */
export interface GalleryPricingSource {
    galleryId: string;
    galleryGroupId?: string;
    /** Display name supplied by the meta-gallery response, when available. */
    galleryName?: string;
    photoCount: number;
}

/**
 * Effective pricing descriptor for a set of child galleries in a
 * meta-gallery. Scope groups deliberately have a null total because their
 * per-photo catalog price is resolved only by the server at checkout.
 */
export interface GalleryPricingGroup {
    key: string;
    licensingMode: CartLicensingMode;
    presetId: string;
    presetName: string | null;
    galleryIds: string[];
    galleryGroupIds: string[];
    photoCount: number;
    totalCents: number | null;
    pricePerItemCents: number | null;
    tiers: VolumeTierConfig[];
    tierIndex: number;
    isMaxTier: boolean;
    nextTierCount: number;
    nextTierLabel: string;
    isVolumePricing: boolean;
}

export interface GalleryLicensingResult {
    /** True when at least one child gallery resolves to volume licensing. */
    isVolumePricing: boolean;
    /** Effective child-gallery groups, including scope groups. */
    groups: GalleryPricingGroup[];
    /** Sum of all volume-group totals; scope totals remain server-priced. */
    volumeSubtotalCents: number;
    /** True while the child terms are still being resolved. */
    isLoading: boolean;
}

/** Volume licensing pricing summary derived from cart items. */
export interface VolumeLicensingResult {
    /** 0-based index of the currently qualifying tier. */
    tierIndex: number;
    /** True when the last tier is active (best discount). */
    isMaxTier: boolean;
    pricePerItemCents: number;
    /** Selected volume-group total, retained for card-level consumers. */
    totalCents: number;
    nextTierCount: number;
    nextTierLabel: string;
    /** Effective tier structure (configurable per brand/gallery). */
    tiers: VolumeTierConfig[];
    isVolumePricing: boolean;
    /** All effective pricing groups, including scope groups. */
    groups?: CartPricingGroup[];
    /** Sum of all scope and volume groups. */
    groupedTotalCents?: number;
    /** Sum of all volume groups (the amount eligible for a coupon). */
    volumeSubtotalCents?: number;
    /** Actual volume prices keyed by photo id, used for coupon/package math. */
    volumeItemPrices?: Record<string, number>;
}

export interface CartContextType {
    items: CartItem[];
    /** Signed quote-link token associated with the current cart, if any. */
    quoteToken: string | null;
    addToCart: (item: CartItem) => void;
    setQuoteToken: (token: string | null) => void;
    removeFromCart: (photoId: string) => void;
    clearCart: () => void;
    totalAmount: number;
    itemCount: number;
    /** Volume licensing pricing summary (undefined for non-volume-licensing brands). */
    volumeLicensing?: VolumeLicensingResult;
}

export const CartContext = createContext<CartContextType | undefined>(undefined);

export const useCart = () => {
    const context = useContext(CartContext);
    if (!context) throw new Error('useCart must be used within CartProvider');
    return context;
};