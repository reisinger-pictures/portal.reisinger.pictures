import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useBrand } from '../brandRegistry';

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

    it('returns rp brand for production hostname', () => {
        mockHostname('portal.reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('rp');
    });

    it('returns rp brand for reisinger.pictures hostname', () => {
        mockHostname('reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('rp');
    });

    it('defaults to rp for production hostname', () => {
        mockHostname('buy.reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('rp');
    });

    it('resolves srp for srp.localhost via dev fallback', () => {
        mockHostname('srp.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('srp');
    });

    it('resolves brand from *.localhost subdomain', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('buy');
    });

    it('returns correct logo paths for rp', () => {
        mockHostname('portal.reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/rp/android-chrome-192x192.png');
    });

    it('returns correct logo paths for srp dev brand', () => {
        mockHostname('srp.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/srp/android-chrome-192x192.png');
    });

    it('returns correct logo paths for buy dev brand', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/buy/android-chrome-192x192.png');
    });

    it('returns features from brand config', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.features).toBeDefined();
    });

    it('handles case-insensitive hostname matching', () => {
        mockHostname('BUY.LOCALHOST');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('buy');
    });
});
