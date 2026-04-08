import { useState, useEffect, ReactNode } from 'react';
import { CartItem, CartContext } from './CartContext';

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
