import {z} from 'zod';
import type {CartItem, VolumeLicensingResult} from './CartContext';
import {calculateVolumeTotal, type VolumePricingConfig} from './useVolumeLicensing';

export const cartItemSchema = z.object({
    photoId: z.string(),
    filename: z.string().optional(),
    thumb_url: z.string().optional(),
    tier: z.enum(['web', 'print', 'original']),
    galleryId: z.string().optional(),
    galleryGroupId: z.string().optional(),
    useCaseId: z.string().optional(),
    useCaseName: z.string().optional(),
    modifierIds: z.array(z.string()).optional(),
    modifierNames: z.array(z.string()).optional(),
    isQuote: z.boolean().optional(),
    notes: z.string().optional(),
    price: z.number(),
});

export const cartSchema = z.array(cartItemSchema);

// Quote links contain a signed offer token (not a payment/client secret). The
// browser may keep it with the cart so a reload does not lose the authoritative
// offer price. Validate it before accepting anything from localStorage and keep
// the payload deliberately small and printable.
const isPrintableQuoteToken = (value: string): boolean => {
    if (value.trim() !== value) return false;
    for (let index = 0; index < value.length; index += 1) {
        const code = value.charCodeAt(index);
        if (code < 32 || code === 127) return false;
    }
    return true;
};

const quoteTokenSchema = z.string()
    .min(1)
    .max(16_384)
    .refine(isPrintableQuoteToken);

const persistedCartSchema = z.object({
    version: z.literal(1),
    items: cartSchema,
    quoteToken: quoteTokenSchema.nullable(),
}).strict();

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

/**
 * Calculates the server-consistent cart total.
 *
 * A `VolumeLicensingResult` carries the already grouped server inputs. Passing
 * it is the only safe way to total a mixed cart: the legacy boolean mode is
 * retained for callers that do not have pricing metadata yet. A signed quote
 * token bypasses both strategies and uses the immutable per-item offer values.
 */
export function calculateTotalAmount(
    items: CartItem[],
    pricing: boolean | VolumeLicensingResult | VolumePricingConfig = false,
    quoteToken: string | null = null,
): number {
    if (quoteToken !== null) {
        return items.reduce((sum, item) => sum + (item.isQuote ? 0 : item.price), 0);
    }

    if (typeof pricing === 'object' && pricing !== null) {
        if ('groupedTotalCents' in pricing && pricing.groupedTotalCents !== undefined) {
            return pricing.groupedTotalCents;
        }
        if ('isVolumePricing' in pricing) {
            if (pricing.isVolumePricing) return pricing.totalCents;
        } else {
            return calculateVolumeTotal(items, pricing);
        }
    }

    if (pricing === true) {
        return calculateVolumeTotal(items);
    }

    return items.reduce((sum, item) => sum + (item.isQuote ? 0 : item.price), 0);
}

export type CartLoadError = 'none' | 'invalid-json' | 'schema';

export interface CartLoadResult {
    items: CartItem[];
    error: CartLoadError;
}

export interface CartStateLoadResult extends CartLoadResult {
    quoteToken: string | null;
}

/**
 * Verteilt einen Gesamtbetrag (in Cent) exakt und gleichmäßig auf `count` Positionen.
 *
 * `Math.round(total/count)` pro Position kann den Gesamtbetrag um bis zu
 * `count/2` Cent verfälschen (Rundungsdrift). Deshalb wird der Cent-Rest
 * deterministisch auf die ersten Positionen verteilt, sodass die Summe der
 * Einzelpreise wieder exakt `totalCents` ergibt.
 */
export function splitTotalEvenly(totalCents: number, count: number): number[] {
    if (count <= 0) return [];
    const base = Math.floor(totalCents / count);
    const remainder = totalCents - base * count;
    return Array.from({length: count}, (_, index) => base + (index < remainder ? 1 : 0));
}

function parseCartState(saved: string | null): CartStateLoadResult {
    if (!saved) return {items: [], quoteToken: null, error: 'none'};

    let parsed: unknown;
    try {
        parsed = JSON.parse(saved);
    } catch {
        return {items: [], quoteToken: null, error: 'invalid-json'};
    }

    const persisted = persistedCartSchema.safeParse(parsed);
    if (persisted.success) {
        return {
            items: persisted.data.items,
            // A token without its bound photo set is stale. Never resurrect a
            // quote token together with an empty cart.
            quoteToken: persisted.data.items.length > 0 ? persisted.data.quoteToken : null,
            error: 'none',
        };
    }

    // Carts written before quote-token metadata used a bare array. Keep that
    // format readable, but never accept an arbitrary object as cart data.
    const legacy = cartSchema.safeParse(parsed);
    if (legacy.success) return {items: legacy.data, quoteToken: null, error: 'none'};

    if (import.meta.env.DEV) {
        console.warn('LocalStorage Cart Mismatch:', persisted.error);
    }
    return {items: [], quoteToken: null, error: 'schema'};
}

/**
 * Loads and validates both the cart items and the optional signed quote token.
 * The strict envelope rejects unknown fields, including payment client secrets.
 */
export function loadCartState(saved: string | null): CartStateLoadResult {
    return parseCartState(saved);
}

/**
 * Backwards-compatible item-only view used by callers that do not need quote
 * metadata. Legacy array carts still return the same result shape as before.
 */
export function loadCartItems(saved: string | null): CartLoadResult {
    const result = parseCartState(saved);
    return {items: result.items, error: result.error};
}

/**
 * Persists only the validated cart shape. Normal carts retain the legacy array
 * format; quote carts use a versioned envelope containing the signed offer
 * token. Payment client secrets are neither part of the input nor serialized.
 */
export function persistCartItems(key: string, items: CartItem[], quoteToken: string | null = null): boolean {
    const validatedItems = cartSchema.safeParse(items);
    if (!validatedItems.success) return false;
    if (quoteToken !== null && !quoteTokenSchema.safeParse(quoteToken).success) return false;

    try {
        const effectiveQuoteToken = validatedItems.data.length > 0 ? quoteToken : null;
        if (validatedItems.data.length > 0 || localStorage.getItem(key)) {
            const serialized = effectiveQuoteToken === null
                ? JSON.stringify(validatedItems.data)
                : JSON.stringify({
                    version: 1,
                    items: validatedItems.data,
                    quoteToken: effectiveQuoteToken,
                });
            localStorage.setItem(key, serialized);
        }
        return true;
    } catch {
        return false;
    }
}
