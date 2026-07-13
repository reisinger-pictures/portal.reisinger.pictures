import {describe, it, expect} from 'vitest';
import {
    getBrandFromHostname,
    getBrandTheme,
} from '../brandRegistry';

describe('brandRegistry', () => {
    describe('getBrandFromHostname', () => {
        it('resolves srp for buy.* subdomains', () => {
            expect(getBrandFromHostname('buy.localhost')).toBe('srp');
            expect(getBrandFromHostname('buy.reisinger.pictures')).toBe('srp');
            expect(getBrandFromHostname('BUY.EXAMPLE.COM')).toBe('srp');
        });

        it('defaults to rp for non-SRP hosts', () => {
            expect(getBrandFromHostname('portal.localhost')).toBe('rp');
            expect(getBrandFromHostname('portal.reisinger.pictures')).toBe('rp');
            expect(getBrandFromHostname('portal.test')).toBe('rp');
            expect(getBrandFromHostname('example.com')).toBe('rp');
            expect(getBrandFromHostname('localhost')).toBe('rp');
        });
    });

    describe('getBrandTheme', () => {
        it('returns correct theme for rp', () => {
            const theme = getBrandTheme('rp');
            expect(theme.light).toBe('reisinger-light');
            expect(theme.dark).toBe('b2b-dark');
        });

        it('returns correct theme for srp', () => {
            const theme = getBrandTheme('srp');
            expect(theme.light).toBe('srp-light');
            expect(theme.dark).toBe('srp-dark');
        });

        it('falls back to default theme for unknown brand', () => {
            const theme = getBrandTheme('unknown');
            expect(theme.light).toBe('reisinger-light');
            expect(theme.dark).toBe('b2b-dark');
        });
    });
});
