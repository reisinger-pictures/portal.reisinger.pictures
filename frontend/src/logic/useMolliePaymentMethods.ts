import useSWR from 'swr';
import {fetcher} from '../api';

const PAYMENT_METHODS_URL = '/api/payment-methods';

interface PaymentMethodsResponse {
    methods?: unknown;
}

/**
 * The portal-side Mollie allowlist for the cart.
 *
 * Read-only and unauthenticated in shape but auth-required by the route: a
 * failure (including an expired session) yields no methods, which leaves the
 * cart exactly as before this feature — one online option with the server
 * default. Nothing here is payment evidence; the checkout endpoint validates
 * the submitted id again.
 */
export function useMolliePaymentMethods(): string[] {
    const {data} = useSWR<PaymentMethodsResponse>(PAYMENT_METHODS_URL, fetcher, {
        shouldRetryOnError: false,
        revalidateOnFocus: false,
        dedupingInterval: 60_000,
    });

    if (data === undefined || !Array.isArray(data.methods)) {
        return [];
    }

    return data.methods.filter((id): id is string => typeof id === 'string' && id.length > 0);
}
