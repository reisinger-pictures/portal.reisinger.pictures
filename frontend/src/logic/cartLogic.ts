import {z} from 'zod';
import {CartItem} from './CartContext';

export const cartItemSchema = z.object({
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
    price: z.number(),
});

export const cartSchema = z.array(cartItemSchema);

/** Ersetzt ein Item mit gleicher photoId, hängt sonst an (verhaltensgleich zu CartProvider.addToCart). */
export function addToCartPure(prev: CartItem[], item: CartItem): CartItem[] {
    const existing = prev.find(i => i.photoId === item.photoId);
    if (existing) {
        return prev.map(i => (i.photoId === item.photoId ? item : i));
    }
    return [...prev, item];
}

/** Filtert das Item mit der photoId heraus (verhaltensgleich zu CartProvider.removeFromCart). */
export function removeFromCartPure(prev: CartItem[], photoId: string): CartItem[] {
    return prev.filter(i => i.photoId !== photoId);
}

/** Summe der Preise aller Nicht-Quote-Items (isQuote falsy → zählt). */
export function calculateTotalAmount(items: CartItem[]): number {
    return items.reduce((sum, item) => sum + (item.isQuote ? 0 : item.price), 0);
}

export type CartLoadError = 'none' | 'invalid-json' | 'schema';

export interface CartLoadResult {
    items: CartItem[];
    error: CartLoadError;
}

/**
 * Reine Lade-Logik (aus CartProvider extrahiert, verhaltensgleich): validiert den gespeicherten
 * Cart-Inhalt via Zod. Schema-Mismatch → console.warn (wie im Original).
 */
export function loadCartItems(saved: string | null): CartLoadResult {
    if (!saved) return {items: [], error: 'none'};
    let parsed: unknown;
    try {
        parsed = JSON.parse(saved);
    } catch {
        return {items: [], error: 'invalid-json'};
    }
    const validation = cartSchema.safeParse(parsed);
    if (validation.success) return {items: validation.data, error: 'none'};
    console.warn('LocalStorage Cart Mismatch:', validation.error);
    return {items: [], error: 'schema'};
}
