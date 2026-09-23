import {z} from 'zod';
import type {CartItem} from './CartContext';

const STORAGE_PREFIX = 'rp_checkout_session_v1:';
const CART_MARKER_PATTERN = /^cart-v1-[0-9a-f]{16}$/;

const checkoutSessionSchema = z.object({
    version: z.literal(1),
    userId: z.string().min(1).max(128),
    cartMarker: z.string().regex(CART_MARKER_PATTERN),
    idempotencyKey: z.string().uuid()
}).strict();

export type CheckoutSession = z.infer<typeof checkoutSessionSchema>;

export interface CheckoutSessionStorage {
    getItem: (key: string) => string | null;
    setItem: (key: string, value: string) => void;
    removeItem: (key: string) => void;
}

export type CheckoutMarkerItem = Pick<
    CartItem,
    'photoId' | 'tier' | 'useCaseId' | 'modifierIds' | 'isQuote' | 'notes'
>;

const defaultKeyFactory = () => crypto.randomUUID();

const getDefaultStorage = (): CheckoutSessionStorage | null => {
    try {
        return typeof sessionStorage === 'undefined' ? null : sessionStorage;
    } catch {
        return null;
    }
};

const hash32 = (value: string, seed: number): string => {
    let hash = seed >>> 0;
    for (let index = 0; index < value.length; index += 1) {
        hash ^= value.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
};

/**
 * Creates a deterministic, non-sensitive marker for associating a persisted
 * idempotency key with the current cart/quote session. The marker is local
 * routing metadata; the opaque key remains the server recovery anchor.
 */
export function createCheckoutCartMarker(
    items: readonly CheckoutMarkerItem[],
    quoteToken: string | null = null
): string {
    const canonicalItems = items.map((item) => JSON.stringify([
        item.photoId,
        item.tier,
        item.useCaseId ?? null,
        [...(item.modifierIds ?? [])].sort(),
        item.isQuote === true,
        item.notes ?? null
    ])).sort();
    const canonical = JSON.stringify({items: canonicalItems, quoteToken});

    return `cart-v1-${hash32(canonical, 0x811c9dc5)}${hash32(canonical, 0x9e3779b9)}`;
}

export const checkoutSessionStorageKey = (userId: string): string => (
    `${STORAGE_PREFIX}${encodeURIComponent(userId)}`
);

export const parseCheckoutSession = (
    rawValue: string | null,
    userId: string,
    cartMarker: string
): CheckoutSession | null => {
    if (!rawValue) return null;

    try {
        const parsed = checkoutSessionSchema.safeParse(JSON.parse(rawValue) as unknown);
        if (!parsed.success) return null;
        if (parsed.data.userId !== userId || parsed.data.cartMarker !== cartMarker) return null;
        return parsed.data;
    } catch {
        return null;
    }
};

const persistCheckoutSession = (
    session: CheckoutSession,
    storage: CheckoutSessionStorage | null
): CheckoutSession => {
    try {
        storage?.setItem(checkoutSessionStorageKey(session.userId), JSON.stringify(session));
    } catch {
        // Recovery still works for the mounted view when sessionStorage is blocked.
    }
    return session;
};

const createCheckoutSession = (
    userId: string,
    cartMarker: string,
    createKey: () => string,
    storage: CheckoutSessionStorage | null
): CheckoutSession => {
    const candidate = checkoutSessionSchema.safeParse({
        version: 1,
        userId,
        cartMarker,
        idempotencyKey: createKey()
    });
    if (!candidate.success) throw new Error('Unable to create a valid checkout recovery session.');
    return persistCheckoutSession(candidate.data, storage);
};

export function loadOrCreateCheckoutSession(
    userId: string,
    cartMarker: string,
    createKey: () => string = defaultKeyFactory,
    storage: CheckoutSessionStorage | null = getDefaultStorage()
): CheckoutSession {
    const key = checkoutSessionStorageKey(userId);
    let rawValue: string | null;
    try {
        rawValue = storage?.getItem(key) ?? null;
    } catch {
        rawValue = null;
    }

    const existing = parseCheckoutSession(rawValue, userId, cartMarker);
    if (existing) return existing;

    if (rawValue) {
        try {
            storage?.removeItem(key);
        } catch {
            // A blocked or stale storage entry must not block checkout.
        }
    }

    return createCheckoutSession(userId, cartMarker, createKey, storage);
}

export function replaceCheckoutSession(
    userId: string,
    cartMarker: string,
    createKey: () => string = defaultKeyFactory,
    storage: CheckoutSessionStorage | null = getDefaultStorage()
): CheckoutSession {
    return createCheckoutSession(userId, cartMarker, createKey, storage);
}

export function clearCheckoutSession(
    userId: string,
    storage: CheckoutSessionStorage | null = getDefaultStorage()
): void {
    try {
        storage?.removeItem(checkoutSessionStorageKey(userId));
    } catch {
        // Logout and cart reset remain usable when storage is unavailable.
    }
}
