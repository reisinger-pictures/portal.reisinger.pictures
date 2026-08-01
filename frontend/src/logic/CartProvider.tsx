import {useState, useEffect, useCallback, useMemo, useRef, ReactNode} from 'react';
import {CartItem, CartContext} from './CartContext';
import {useAuth} from './useAuth';
import {useUI} from '../ui/components/UIContext';
import {addToCartPure, removeFromCartPure, calculateTotalAmount, loadCartItems} from './cartLogic';
import {useVolumeLicensing} from './useVolumeLicensing';
import {trackEvent, TRACKING_EVENTS} from './tracking';

export interface CartProviderProps {
    children: ReactNode;
}

export function CartProvider({children}: CartProviderProps) {
    const {user} = useAuth();
    const {showToast} = useUI();
    const cartKey = `rp_cart_${user?.id ? btoa(String(user.id)) : 'guest'}`;

    const [items, setItems] = useState<CartItem[]>(() => {
        const result = loadCartItems(localStorage.getItem(cartKey));
        return result.items;
    });
    const loadingRef = useRef(false);

    // Re-Load bei User-Wechsel mit Zod-Validierung (reine Logik in cartLogic.ts)
    useEffect(() => {
        loadingRef.current = true;
        queueMicrotask(() => {
            const result = loadCartItems(localStorage.getItem(cartKey));
            if (result.error === 'invalid-json') {
                showToast('error', 'Warenkorb konnte nicht geladen werden.');
            }
            setItems(result.items);
            loadingRef.current = false;
        });
    }, [cartKey, showToast]);

    // Speichern bei Änderungen
    useEffect(() => {
        if (loadingRef.current) return;
        try {
            if (items.length > 0 || localStorage.getItem(cartKey)) {
                localStorage.setItem(cartKey, JSON.stringify(items));
            }
        } catch {
            showToast('error', 'Warenkorb konnte nicht gespeichert werden.');
        }
    }, [items, cartKey, showToast]);

    const addToCart = useCallback((item: CartItem) => {
        setItems(prev => addToCartPure(prev, item));
    }, []);

    const removeFromCart = useCallback((photoId: string) => {
        setItems(prev => removeFromCartPure(prev, photoId));
        trackEvent(TRACKING_EVENTS.remove_from_cart, { photo_id: photoId });
    }, []);

    const clearCart = useCallback(() => setItems([]), []);

    // Derived values: volume licensing pricing + totalAmount (licensing-mode-aware)
    const volumeLicensing = useVolumeLicensing(items);
    const totalAmount = calculateTotalAmount(items, volumeLicensing.isVolumePricing);
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
