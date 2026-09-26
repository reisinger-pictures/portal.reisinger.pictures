import {useState, useEffect, useRef, ReactNode} from 'react';
import {CartItem, CartContext} from './CartContext';
import {useAuth} from './useAuth';
import {useUI} from '../ui/components/UIContext';
import {addToCartPure, removeFromCartPure, calculateTotalAmount, loadCartState, persistCartItems} from './cartLogic';
import {clearCheckoutSession} from './checkoutSession';
import {useVolumeLicensing} from './useVolumeLicensing';

export interface CartProviderProps {
    children: ReactNode;
}

export function CartProvider({children}: CartProviderProps) {
    const {user} = useAuth();
    const {showToast} = useUI();
    const cartKey = `rp_cart_${user?.id ? btoa(String(user.id)) : 'guest'}`;

    const [cartState, setCartState] = useState(() => {
        return loadCartState(cartKey);
    });
    const {items, quoteToken} = cartState;
    const loadingRef = useRef(false);

    // Re-Load bei User-Wechsel mit Zod-Validierung (reine Logik in cartLogic.ts)
    useEffect(() => {
        loadingRef.current = true;
        queueMicrotask(() => {
            const result = loadCartState(cartKey);
            if (result.error === 'invalid-json') {
                showToast('error', 'Warenkorb konnte nicht geladen werden.');
            }
            setCartState(result);
            loadingRef.current = false;
        });
    }, [cartKey, showToast]);

    // Speichern bei Änderungen
    useEffect(() => {
        if (loadingRef.current) return;
        if (!persistCartItems(cartKey, items, quoteToken)) {
            showToast('error', 'Warenkorb konnte nicht gespeichert werden.');
        }
    }, [items, quoteToken, cartKey, showToast]);

    const addToCart = (item: CartItem) => {
        setCartState(prev => {
            const isExistingItem = prev.items.some(existing => existing.photoId === item.photoId);
            // A signed offer is bound to its exact photo set. Updating an
            // existing item (for example its notes) keeps the token. A newly
            // added item replaces the quote cart instead of leaving orphaned
            // offer items that would be priced without their token.
            return {
                ...prev,
                items: prev.quoteToken !== null && !isExistingItem
                    ? [item]
                    : addToCartPure(prev.items, item),
                quoteToken: isExistingItem ? prev.quoteToken : null,
            };
        });
    };

    const setQuoteToken = (token: string | null) => {
        setCartState(prev => ({
            ...prev,
            quoteToken: prev.items.length > 0 ? token : null,
        }));
    };

    const removeFromCart = (photoId: string) => {
        setCartState(prev => {
            const nextItems = removeFromCartPure(prev.items, photoId);
            // A signed offer is bound to the exact photo set. Removing any
            // matching item invalidates it, even when items remain.
            return {
                ...prev,
                items: nextItems,
                quoteToken: nextItems.length === prev.items.length ? prev.quoteToken : null,
            };
        });
    };

    const clearCart = () => {
        if (user?.id) clearCheckoutSession(user.id);
        setCartState(prev => ({...prev, items: [], quoteToken: null}));
    };

    // Derived values: volume licensing pricing + totalAmount (licensing-mode-aware)
    const volumeLicensing = useVolumeLicensing(items);
    const totalAmount = calculateTotalAmount(items, volumeLicensing, quoteToken);
    const itemCount = items.length;

    const contextValue = {items, quoteToken, addToCart, setQuoteToken, removeFromCart, clearCart, totalAmount, itemCount, volumeLicensing};

    return (
        <CartContext.Provider value={contextValue}>
            {children}
        </CartContext.Provider>
    );
}
