import { createContext, useContext } from 'react';
import { ResolutionTier } from './usePricing';

export interface CartItem {
    photoId: string;
    filename?: string;
    thumb_url?: string;
    tier: ResolutionTier;
    useCaseId?: string;
    useCaseName?: string;
    modifierIds?: string[];
    modifierNames?: string[];
        isQuote?: boolean;
    notes?: string;
    price: number;
}

export interface CartContextType {
    items: CartItem[];
    addToCart: (item: CartItem) => void;
    removeFromCart: (photoId: string) => void;
    clearCart: () => void;
    totalAmount: number;
    itemCount: number;
}

export const CartContext = createContext<CartContextType | undefined>(undefined);

export const useCart = () => {
    const context = useContext(CartContext);
    if (!context) throw new Error('useCart must be used within CartProvider');
    return context;
};