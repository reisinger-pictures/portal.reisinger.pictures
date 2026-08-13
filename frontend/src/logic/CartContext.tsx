import { createContext, useContext } from 'react';
import { ResolutionTier } from './pricingLogic';

export interface CartItem {
    photoId: string;
    filename?: string;
    thumb_url?: string;
    tier: ResolutionTier;
    galleryId?: string;
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

/** Volume licensing pricing summary derived from cart items. */
export interface VolumeLicensingResult {
    /** 0-based index of the currently qualifying tier. */
    tierIndex: number;
    /** True when the last tier is active (best discount). */
    isMaxTier: boolean;
    pricePerItemCents: number;
    totalCents: number;
    nextTierCount: number;
    nextTierLabel: string;
    /** Effective tier structure (configurable per brand/gallery). */
    tiers: VolumeTierConfig[];
    isVolumePricing: boolean;
}

export interface CartContextType {
    items: CartItem[];
    addToCart: (item: CartItem) => void;
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