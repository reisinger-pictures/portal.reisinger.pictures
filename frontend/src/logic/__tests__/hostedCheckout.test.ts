import {describe, expect, it, vi, afterEach} from 'vitest';
import {assignLocation, isHostedCheckoutUrl} from '../hostedCheckout';

describe('isHostedCheckoutUrl', () => {
    it.each([
        'https://www.mollie.com/checkout/select-method/tr_abc123',
        'https://mollie.com/checkout/select-method/tr_abc123',
        'https://payments.mollie.com/pay/tr_abc123',
    ])('accepts a Mollie checkout URL (%s)', (url) => {
        expect(isHostedCheckoutUrl(url)).toBe(true);
    });

    it.each([
        ['plain http', 'http://www.mollie.com/checkout/select-method/tr_abc123'],
        ['a look-alike host', 'https://mollie.com.evil.example/checkout'],
        ['a look-alike prefix', 'https://notmollie.com/checkout'],
        ['a foreign host', 'https://evil.example/checkout'],
        ['a javascript URL', 'javascript:alert(1)'],
        ['a relative path', '/checkout/return'],
        ['an empty value', ''],
    ])('rejects %s', (_label, url) => {
        expect(isHostedCheckoutUrl(url)).toBe(false);
    });
});

describe('assignLocation', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('navigates the browser to the given URL', () => {
        const assign = vi.fn();
        // jsdom declares `location` as unforgeable, so the whole window global
        // is replaced for this one call.
        vi.stubGlobal('window', {location: {assign}});

        assignLocation('https://www.mollie.com/checkout/select-method/tr_abc123');

        expect(assign).toHaveBeenCalledWith('https://www.mollie.com/checkout/select-method/tr_abc123');
    });
});
