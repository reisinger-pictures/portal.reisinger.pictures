import { useState, useEffect, ReactNode } from 'react';
import { CartItem, CartContext } from './CartContext';
import { useAuth } from './useAuth';
import { useUI } from '../ui/components/UIContext';

export function CartProvider({ children }: { children: ReactNode }) {
    const { user } = useAuth();
    const { showToast } = useUI();
    const cartKey = `rp_cart_${user?.id || 'guest'}`;

    const [items, setItems] = useState<CartItem[]>([]);

    // Initiale Lade-Logik & Re-Load bei User-Wechsel
    useEffect(() => {
        let mounted = true;
        Promise.resolve().then(() => {
            if (!mounted) return;
            try {
                const saved = localStorage.getItem(cartKey);
                setItems(saved ? JSON.parse(saved) : []);
            } catch {
                showToast('error', 'Warenkorb konnte nicht geladen werden.');
                setItems([]);
            }
        });
        return () => { mounted = false; };
    }, [cartKey, showToast]);

    // Speichern bei Änderungen
    useEffect(() => {
        try {
            if (items.length > 0 || localStorage.getItem(cartKey)) {
                localStorage.setItem(cartKey, JSON.stringify(items));
            }
        } catch {
            showToast('error', 'Warenkorb konnte nicht gespeichert werden.');
        }
    }, [items, cartKey, showToast]);

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
