import {useState, useEffect, useCallback, useMemo, ReactNode} from 'react';
import {CartItem, CartContext} from './CartContext';
import {useAuth} from './useAuth';
import {useUI} from '../ui/components/UIContext';
import {addToCartPure, removeFromCartPure, calculateTotalAmount, loadCartItems} from './cartLogic';
import {useBrand} from './useBrand';
import {useVolumeLicensing} from './useVolumeLicensing';

export interface CartProviderProps {
    children: ReactNode;
}

export function CartProvider({children}: CartProviderProps) {
    const {user} = useAuth();
    const {showToast} = useUI();
    const {brand} = useBrand();
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

    const addToCart = useCallback((item: CartItem) => {
        setItems(prev => addToCartPure(prev, item));
    }, []);

    const removeFromCart = useCallback((photoId: string) => {
        setItems(prev => removeFromCartPure(prev, photoId));
    }, []);

    const clearCart = useCallback(() => setItems([]), []);

    // Derived values: volume licensing pricing + totalAmount (brand-aware)
    const volumeLicensing = useVolumeLicensing(items);
    const totalAmount = calculateTotalAmount(items, brand);
    const itemCount = items.length;

    const contextValue = useMemo(
        () => ({items, addToCart, removeFromCart, clearCart, totalAmount, itemCount, volumeLicensing}),
        [items, addToCart, removeFromCart, clearCart, totalAmount, itemCount, volumeLicensing],
    );

    return (
        <CartContext.Provider value={contextValue}>
            {children}
        </CartContext.Provider>
    );
}
