import {describe, it, expect, vi, afterEach} from 'vitest';
import {
    getBrandFromHostname,
    getBrandTheme,
    applyBrandColors,
    applyTheme,
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
            expect(theme.light).toBe('rp-light');
            expect(theme.dark).toBe('rp-dark');
        });

        it('falls back to default theme for srp (removed brand)', () => {
            const theme = getBrandTheme('srp');
            expect(theme.light).toBe('rp-light');
            expect(theme.dark).toBe('rp-dark');
        });

        it('derives theme from brand config when available', () => {
            const config = {theme: 'rp'} as any;
            const theme = getBrandTheme('rp', config);
            expect(theme.light).toBe('rp-light');
            expect(theme.dark).toBe('rp-dark');
        });

        it('falls back to default theme for unknown brand', () => {
            const theme = getBrandTheme('unknown');
            expect(theme.light).toBe('rp-light');
            expect(theme.dark).toBe('rp-dark');
        });
    });

    describe('applyTheme', () => {
        afterEach(() => {
            vi.unstubAllGlobals();
        });

        it('removes the previous matchMedia change listener on re-apply', () => {
            const addEventListener = vi.fn();
            const removeEventListener = vi.fn();
            const mediaQueryList = {
                matches: false,
                media: '(prefers-color-scheme: dark)',
                onchange: null,
                addEventListener,
                removeEventListener,
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            } as unknown as MediaQueryList;
            vi.stubGlobal('matchMedia', vi.fn(() => mediaQueryList));

            applyTheme();
            applyTheme();

            expect(addEventListener).toHaveBeenCalledTimes(2);
            expect(removeEventListener).toHaveBeenCalledTimes(1);
        });
    });

    describe('applyBrandColors', () => {
        afterEach(() => {
            document.documentElement.style.removeProperty('--color-primary');
            document.documentElement.style.removeProperty('--color-secondary');
        });

        it('applies validated persisted colors to the document theme variables', () => {
            applyBrandColors({
                primary_color: '#123456',
                secondary_color: '#ABCDEF',
            });

            expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('#123456');
            expect(document.documentElement.style.getPropertyValue('--color-secondary')).toBe('#ABCDEF');
        });

        it('removes stale variables for missing or unsafe values', () => {
            applyBrandColors({
                primary_color: '#123456',
                secondary_color: '#ABCDEF',
            });
            applyBrandColors({ primary_color: 'red; background: url(unsafe)' });

            expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('');
            expect(document.documentElement.style.getPropertyValue('--color-secondary')).toBe('');

            applyBrandColors(null);
            expect(document.documentElement.style.getPropertyValue('--color-secondary')).toBe('');
        });
    });
});
