import {useState, useEffect, ReactNode} from 'react';
import {CartItem, CartContext} from './CartContext';
import {useAuth} from './useAuth';
import {useUI} from '../ui/components/UIContext';
import {addToCartPure, removeFromCartPure, calculateTotalAmount, loadCartItems} from './cartLogic';

export interface CartProviderProps {
    children: ReactNode;
}

export function CartProvider({children}: CartProviderProps) {
    const {user} = useAuth();
    const {showToast} = useUI();
    const cartKey = `rp_cart_${user?.id || 'guest'}`;

    const [items, setItems] = useState<CartItem[]>([]);
    const [isLoaded, setIsLoaded] = useState(false);

    // Initiale Lade-Logik & Re-Load bei User-Wechsel mit Zod-Validierung (reine Logik in cartLogic.ts)
    useEffect(() => {
        queueMicrotask(() => setIsLoaded(false));
        const result = loadCartItems(localStorage.getItem(cartKey));
        if (result.error === 'invalid-json') {
            showToast('error', 'Warenkorb konnte nicht geladen werden.');
        }
        queueMicrotask(() => setItems(result.items));
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
        setItems(prev => addToCartPure(prev, item));
    };

    const removeFromCart = (photoId: string) => {
        setItems(prev => removeFromCartPure(prev, photoId));
    };

    const clearCart = () => setItems([]);

    const totalAmount = calculateTotalAmount(items);
    const itemCount = items.length;

    return (
        <CartContext.Provider value={{items, addToCart, removeFromCart, clearCart, totalAmount, itemCount}}>
            {children}
        </CartContext.Provider>
    );
}
