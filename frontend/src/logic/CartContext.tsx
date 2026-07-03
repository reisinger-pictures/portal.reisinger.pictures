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

/** Volume licensing pricing summary derived from cart items. */
export interface VolumeLicensingResult {
    tier: 1 | 2 | 3;
    pricePerItemCents: number;
    totalCents: number;
    nextTierCount: number;
    nextTierLabel: string;
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