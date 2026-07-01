import {describe, it, expect} from 'vitest';
import {
    BRAND_SRP,
    BRAND_B2B,
    brandPrefix,
    getBrandFromHostname,
    isSrpBrand,
} from '../brandRegistry';

describe('brandRegistry', () => {
    describe('getBrandFromHostname', () => {
        it('resolves SRP for buy.* subdomains', () => {
            expect(getBrandFromHostname('buy.localhost')).toBe(BRAND_SRP);
            expect(getBrandFromHostname('buy.reisinger.pictures')).toBe(BRAND_SRP);
            expect(getBrandFromHostname('BUY.EXAMPLE.COM')).toBe(BRAND_SRP);
        });

        it('defaults to B2B for non-SRP hosts', () => {
            expect(getBrandFromHostname('portal.localhost')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('portal.reisinger.pictures')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('portal.test')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('example.com')).toBe(BRAND_B2B);
            expect(getBrandFromHostname('localhost')).toBe(BRAND_B2B);
        });
    });

    describe('isSrpBrand', () => {
        it('returns true only for SRP', () => {
            expect(isSrpBrand(BRAND_SRP)).toBe(true);
            expect(isSrpBrand(BRAND_B2B)).toBe(false);
        });
    });

    describe('brandPrefix', () => {
        it('returns srp_ for SRP and empty for B2B', () => {
            expect(brandPrefix(BRAND_SRP)).toBe('srp_');
            expect(brandPrefix(BRAND_B2B)).toBe('');
        });
    });
});
