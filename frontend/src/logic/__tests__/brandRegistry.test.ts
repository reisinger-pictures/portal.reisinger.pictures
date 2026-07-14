import {describe, it, expect} from 'vitest';
import {
    getBrandFromHostname,
    getBrandTheme,
} from '../brandRegistry';

describe('brandRegistry', () => {
    describe('getBrandFromHostname', () => {
        it('resolves subdomain for *.localhost dev fallback', () => {
            expect(getBrandFromHostname('srp.localhost')).toBe('srp');
            expect(getBrandFromHostname('buy.localhost')).toBe('buy');
            expect(getBrandFromHostname('acme.localhost')).toBe('acme');
            expect(getBrandFromHostname('portal.localhost')).toBe('portal');
            expect(getBrandFromHostname('SRP.LOCALHOST')).toBe('srp');
        });

        it('defaults to rp for production hosts', () => {
            expect(getBrandFromHostname('portal.reisinger.pictures')).toBe('rp');
            expect(getBrandFromHostname('buy.reisinger.pictures')).toBe('rp');
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

        it('falls back to default theme for srp (removed brand)', () => {
            const theme = getBrandTheme('srp');
            expect(theme.light).toBe('reisinger-light');
            expect(theme.dark).toBe('b2b-dark');
        });

        it('falls back to default theme for unknown brand', () => {
            const theme = getBrandTheme('unknown');
            expect(theme.light).toBe('reisinger-light');
            expect(theme.dark).toBe('b2b-dark');
        });
    });
});
