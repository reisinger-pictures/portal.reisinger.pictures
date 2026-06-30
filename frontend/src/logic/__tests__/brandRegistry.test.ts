import {describe, it, expect} from 'vitest';
import {
    BRAND_SRP,
    BRAND_B2B,
    brandPrefix,
    getBrandFromHostname,
    isSrpBrand,
    SRP_DEV_HOST,
    SRP_PROD_DOMAIN,
} from '../brandRegistry';

describe('brandRegistry', () => {
    describe('getBrandFromHostname', () => {
        it('resolves SRP for the production domain', () => {
            expect(getBrandFromHostname(`portal.${SRP_PROD_DOMAIN}`)).toBe(BRAND_SRP);
            expect(getBrandFromHostname(SRP_PROD_DOMAIN)).toBe(BRAND_SRP);
        });

        it('resolves SRP for the local dev host', () => {
            expect(getBrandFromHostname(SRP_DEV_HOST)).toBe(BRAND_SRP);
        });

        it('defaults to B2B for unknown / B2B hosts', () => {
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
