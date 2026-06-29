import {describe, it, expect} from 'vitest';
import {
    BRAND_ATR,
    BRAND_B2B,
    brandPrefix,
    getBrandFromHostname,
    isAtrBrand,
    ATR_DEV_HOST,
    ATR_PROD_DOMAIN,
} from '../brandRegistry';

describe('brandRegistry', () => {
    describe('getBrandFromHostname', () => {
        it('resolves ATR for the production domain', () => {
            expect(getBrandFromHostname(`portal.${ATR_PROD_DOMAIN}`)).toBe(BRAND_ATR);
            expect(getBrandFromHostname(ATR_PROD_DOMAIN)).toBe(BRAND_ATR);
        });

        it('resolves ATR for the local dev host', () => {
            expect(getBrandFromHostname(ATR_DEV_HOST)).toBe(BRAND_ATR);
        });

        it('defaults to B2B for unknown / B2B hosts', () => {
            expect(getBrandFromHostname('portal.reisinger.pictures')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('portal.test')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('example.com')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('localhost')).toBe(BRAND_B2B);
        });
    });

    describe('isAtrBrand', () => {
        it('returns true only for ATR', () => {
            expect(isAtrBrand(BRAND_ATR)).toBe(true);
            expect(isAtrBrand(BRAND_B2B)).toBe(false);
        });
    });

    describe('brandPrefix', () => {
        it('returns atr_ for ATR and empty for B2B', () => {
            expect(brandPrefix(BRAND_ATR)).toBe('atr_');
            expect(brandPrefix(BRAND_B2B)).toBe('');
        });
    });
});
