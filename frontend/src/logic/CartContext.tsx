import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { ResolutionTier, UsageTier, DurationTier } from './usePricing';

export interface CartItem {
    photoId: string;
    filename: string;
    tier: ResolutionTier;
    usage: UsageTier;
    duration: DurationTier;
    frequency?: 'einmalig' | 'mehrmalig';
    isQuote?: boolean;
    price: number;
}

interface CartContextType {
    items: CartItem[];
    addToCart: (item: CartItem) => void;
    removeFromCart: (photoId: string) => void;
    clearCart: () => void;
    totalAmount: number;
    itemCount: number;
    }

const CartContext = createContext<CartContextType | undefined>(undefined);

export function CartProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<CartItem[]>(() => {
        try {
            const saved = localStorage.getItem('rp_cart');
            return saved ? JSON.parse(saved) : [];
        } catch (e) { console.error('Cart parse error', e); return []; }
    });
    
        useEffect(() => {
        localStorage.setItem('rp_cart', JSON.stringify(items));
    }, [items]);

    const addToCart = (item: CartItem) => {
        setItems(prev => {
            // Falls das Bild schon im Warenkorb ist, überschreiben wir das Lizenz-Tier
            const existing = prev.find(i => i.photoId === item.photoId);
            if (existing) {
                return prev.map(i => i.photoId === item.photoId ? item : i);
            }
            return [...prev, item];
        });
            };

    const removeFromCart = (photoId: string) => {
        setItems(prev => prev.filter(i => i.photoId !== photoId));
    };

    const clearCart = () => setItems([]);

    const totalAmount = items.reduce((sum, item) => sum + item.price, 0);
    const itemCount = items.length;

    return (
        <CartContext.Provider value={{ items, addToCart, removeFromCart, clearCart, totalAmount, itemCount }}>
            {children}
        </CartContext.Provider>
    );
}

export const useCart = () => {
    const context = useContext(CartContext);
    if (!context) throw new Error('useCart must be used within CartProvider');
    return context;
};
