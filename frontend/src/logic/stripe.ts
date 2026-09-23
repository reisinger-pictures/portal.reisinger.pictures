import {loadStripe, type Stripe} from '@stripe/stripe-js';

const stripePublicKey = import.meta.env.VITE_STRIPE_PUBLIC_KEY?.trim();
const defaultWait = (milliseconds: number) => new Promise<void>(resolve => {
    setTimeout(resolve, milliseconds);
});

export type StripeLoader = (publishableKey: string) => Promise<Stripe | null>;

/**
 * Loads Stripe.js with one bounded retry. The shared promise keeps the public
 * API compatible with Elements and resolves to null after exhausted attempts.
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

/** Shared application-wide Stripe.js loader. Advanced fraud signals remain enabled. */
export const stripePromise = stripePublicKey
    ? loadStripeWithRetry(stripePublicKey)
    : Promise.resolve(null);
