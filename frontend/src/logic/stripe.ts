import {loadStripe, type Stripe} from '@stripe/stripe-js';

const stripePublicKey = import.meta.env.VITE_STRIPE_PUBLIC_KEY?.trim();
const defaultWait = (milliseconds: number) => new Promise<void>(resolve => {
    setTimeout(resolve, milliseconds);
});

export type StripeLoader = (publishableKey: string) => Promise<Stripe | null>;
export type StripeLoaderStatus = 'loading' | 'ready' | 'error';

const stripeLoaderSubscribers = new Set<() => void>();
let stripeLoaderStatus: StripeLoaderStatus = 'loading';
let activeSharedStripeLoad: Promise<boolean> | null = null;
let resolveStripePromise: ((stripe: Stripe) => void) | null = null;

const setStripeLoaderStatus = (status: StripeLoaderStatus): void => {
    if (stripeLoaderStatus === status) return;
    stripeLoaderStatus = status;
    stripeLoaderSubscribers.forEach(listener => listener());
};

/**
 * Shared Elements promise. It deliberately remains pending when a bounded load
 * fails, allowing the checkout UI to expose an explicit retry without remounting
 * Elements or discarding the in-flight PaymentIntent.
 */
export const stripePromise = new Promise<Stripe>(resolve => {
    resolveStripePromise = resolve;
});

/**
 * Loads Stripe.js with bounded retries. Returning null reports exhaustion to
 * direct callers; the shared loader translates that state into a recoverable
 * error state for the checkout UI.
 */
export async function loadStripeWithRetry(
    publishableKey: string,
    loader: StripeLoader = loadStripe,
    wait: (milliseconds: number) => Promise<void> = defaultWait,
    maxAttempts = 2
): Promise<Stripe | null> {
    const attempts = Number.isFinite(maxAttempts) ? Math.max(1, Math.floor(maxAttempts)) : 2;

    for (let attempt = 1; attempt <= attempts; attempt += 1) {
        try {
            const stripe = await loader(publishableKey);
            if (stripe) return stripe;
        } catch {
            // A transient script/network failure is handled by the bounded retry.
        }

        if (attempt < attempts) await wait(attempt * 250);
    }

    return null;
}

export const getStripeLoaderStatus = (): StripeLoaderStatus => stripeLoaderStatus;

export const subscribeToStripeLoaderStatus = (listener: () => void): (() => void) => {
    stripeLoaderSubscribers.add(listener);
    return () => {
        stripeLoaderSubscribers.delete(listener);
    };
};

const loadSharedStripe = (
    loader: StripeLoader,
    wait: (milliseconds: number) => Promise<void>,
    maxAttempts: number
): Promise<boolean> => {
    if (!stripePublicKey) {
        setStripeLoaderStatus('error');
        return Promise.resolve(false);
    }
    if (activeSharedStripeLoad) return activeSharedStripeLoad;

    setStripeLoaderStatus('loading');
    const attempt = loadStripeWithRetry(stripePublicKey, loader, wait, maxAttempts).then(
        stripe => {
            if (!stripe) {
                setStripeLoaderStatus('error');
                return false;
            }

            resolveStripePromise?.(stripe);
            resolveStripePromise = null;
            setStripeLoaderStatus('ready');
            return true;
        },
        () => {
            setStripeLoaderStatus('error');
            return false;
        }
    );
    activeSharedStripeLoad = attempt;
    const clearActiveLoad = () => {
        if (activeSharedStripeLoad === attempt) activeSharedStripeLoad = null;
    };
    void attempt.then(clearActiveLoad, clearActiveLoad);
    return attempt;
};

/** Explicit retry used by the checkout fallback after loader exhaustion. */
export const retryStripeLoader = (
    loader: StripeLoader = loadStripe,
    wait: (milliseconds: number) => Promise<void> = defaultWait,
    maxAttempts = 2
): Promise<boolean> => loadSharedStripe(loader, wait, maxAttempts);

/** Shared application-wide Stripe.js loader. Advanced fraud signals remain enabled. */
if (stripePublicKey) {
    void retryStripeLoader();
} else {
    setStripeLoaderStatus('error');
}
