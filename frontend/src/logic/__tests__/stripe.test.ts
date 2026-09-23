import {describe, expect, it, vi} from 'vitest';
import type {Stripe} from '@stripe/stripe-js';
import {loadStripeWithRetry, type StripeLoader} from '../stripe';

vi.mock('@stripe/stripe-js', () => ({
    loadStripe: vi.fn(() => Promise.resolve(null))
}));

describe('loadStripeWithRetry', () => {
    it('retries a transient Stripe loader failure and returns the loaded instance', async () => {
        const stripe = {} as Stripe;
        const loader = vi.fn<StripeLoader>()
            .mockRejectedValueOnce(new Error('temporary script failure'))
            .mockResolvedValueOnce(stripe);
        const wait = vi.fn(async () => undefined);

        await expect(loadStripeWithRetry('pk_test', loader, wait)).resolves.toBe(stripe);
        expect(loader).toHaveBeenCalledTimes(2);
        expect(wait).toHaveBeenCalledWith(250);
    });

    it('resolves to null after the bounded attempts are exhausted', async () => {
        const loader = vi.fn<StripeLoader>().mockResolvedValue(null);
        const wait = vi.fn(async () => undefined);

        await expect(loadStripeWithRetry('pk_test', loader, wait, 2)).resolves.toBeNull();
        expect(loader).toHaveBeenCalledTimes(2);
        expect(wait).toHaveBeenCalledWith(250);
    });
});
