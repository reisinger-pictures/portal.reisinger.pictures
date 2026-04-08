import { createContext, useContext } from 'react';
import { ResolutionTier, UsageTier, DurationTier } from './usePricing';

export interface CartItem {
    photoId: string;
    filename?: string;
    thumb_url?: string;
    tier: ResolutionTier;
    usage: UsageTier;
    duration: DurationTier;
    frequency?: 'einmalig' | 'mehrmalig';
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