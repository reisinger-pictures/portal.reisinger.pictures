import { useState, useEffect, ReactNode } from 'react';
import { CartItem, CartContext } from './CartContext';
import { useAuth } from './useAuth';
import { useUI } from '../ui/components/UIContext';
import { z } from 'zod';

const cartItemSchema = z.object({
    photoId: z.string(),
    filename: z.string().optional(),
    thumb_url: z.string().optional(),
    tier: z.enum(['web', 'print', 'original']),
    useCaseId: z.string().optional(),
    useCaseName: z.string().optional(),
    modifierIds: z.array(z.string()).optional(),
    modifierNames: z.array(z.string()).optional(),
    isQuote: z.boolean().optional(),
    notes: z.string().optional(),
    price: z.number()
});

const cartSchema = z.array(cartItemSchema);

export interface CartProviderProps {
    children: ReactNode;
}

export function CartProvider({ children }: CartProviderProps) {
    const { user } = useAuth();
    const { showToast } = useUI();
    const cartKey = `rp_cart_${user?.id || 'guest'}`;

    const [items, setItems] = useState<CartItem[]>([]);
    const [isLoaded, setIsLoaded] = useState(false);

    // Initiale Lade-Logik & Re-Load bei User-Wechsel mit Zod-Validierung
    useEffect(() => {
        queueMicrotask(() => setIsLoaded(false));
        try {
            const saved = localStorage.getItem(cartKey);
            if (saved) {
                const parsed = JSON.parse(saved);
                const validation = cartSchema.safeParse(parsed);
                if (validation.success) {
                    queueMicrotask(() => setItems(validation.data));
                } else {
                    console.warn('LocalStorage Cart Mismatch:', validation.error);
                    queueMicrotask(() => setItems([]));
                }
            } else {
                queueMicrotask(() => setItems([]));
            }
        } catch {
            showToast('error', 'Warenkorb konnte nicht geladen werden.');
            queueMicrotask(() => setItems([]));
        }
        queueMicrotask(() => setIsLoaded(true));
    }, [cartKey, showToast]);

    // Speichern bei Änderungen
    useEffect(() => {
        if (!isLoaded) return;
        try {
            if (items.length > 0 || localStorage.getItem(cartKey)) {
                localStorage.setItem(cartKey, JSON.stringify(items));
            }
        } catch {
            showToast('error', 'Warenkorb konnte nicht gespeichert werden.');
        }
    }, [items, cartKey, showToast, isLoaded]);

    const addToCart = (item: CartItem) => {
        setItems(prev => {
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

    const totalAmount = items.reduce((sum, item) => sum + (item.isQuote ? 0 : item.price), 0);
    const itemCount = items.length;

    return (
        <CartContext.Provider value={{ items, addToCart, removeFromCart, clearCart, totalAmount, itemCount }}>
            {children}
        </CartContext.Provider>
    );
}