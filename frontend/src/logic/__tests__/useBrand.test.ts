import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useBrand, BRAND_SRP, BRAND_B2B } from '../brandRegistry';

describe('useBrand', () => {
    const originalLocation = window.location;

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        Object.defineProperty(window, 'location', {
            writable: true,
            value: originalLocation,
        });
    });

    function mockHostname(hostname: string) {
        Object.defineProperty(window, 'location', {
            writable: true,
            value: new URL(`https://${hostname}/`),
        });
    }

    it('returns B2B brand for portal hostname', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe(BRAND_B2B);
        expect(result.current.isSrp).toBe(false);
    });

    it('returns B2B brand for reisinger.pictures hostname', () => {
        mockHostname('reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe(BRAND_B2B);
        expect(result.current.isSrp).toBe(false);
    });

    it('returns SRP brand for buy.reisinger.pictures hostname', () => {
        mockHostname('buy.reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe(BRAND_SRP);
        expect(result.current.isSrp).toBe(true);
    });

    it('returns SRP brand for buy.localhost', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe(BRAND_SRP);
        expect(result.current.isSrp).toBe(true);
    });

    it('returns correct logo paths for B2B', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/rp/android-chrome-192x192.png');
        expect(result.current.svgUrl).toBe('/brands/rp/safari-pinned-tab.svg');
    });

    it('returns correct logo paths for SRP', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/srp/android-chrome-192x192.png');
        expect(result.current.svgUrl).toBe('/brands/srp/safari-pinned-tab.svg');
    });

    it('returns same portalName regardless of brand', () => {
        mockHostname('portal.localhost');
        const { result: b2b } = renderHook(() => useBrand());
        mockHostname('buy.localhost');
        const { result: srp } = renderHook(() => useBrand());
        expect(b2b.current.portalName).toBe('Reisinger Foto Portal');
        expect(srp.current.portalName).toBe('Reisinger Foto Portal');
    });

    it('returns correct impressumUrl for each brand', () => {
        mockHostname('portal.localhost');
        const { result: b2b } = renderHook(() => useBrand());
        expect(b2b.current.impressumUrl).toBe('https://reisinger.pictures/impressum/');

        mockHostname('buy.localhost');
        const { result: srp } = renderHook(() => useBrand());
        expect(srp.current.impressumUrl).toBe('https://buy.reisinger.pictures/impressum/');
    });

    it('handles case-insensitive hostname matching', () => {
        mockHostname('BUY.LOCALHOST');
        const { result } = renderHook(() => useBrand());
        expect(result.current.isSrp).toBe(true);
    });
});
