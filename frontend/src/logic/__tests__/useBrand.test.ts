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

    it('returns rp brand for portal hostname', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('rp');
    });

    it('returns rp brand for reisinger.pictures hostname', () => {
        mockHostname('reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('rp');
    });

    it('returns srp brand for buy.reisinger.pictures hostname', () => {
        mockHostname('buy.reisinger.pictures');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('srp');
    });

    it('returns srp brand for buy.localhost', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('srp');
    });

    it('returns correct logo paths for rp', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/rp/android-chrome-192x192.png');
    });

    it('returns correct logo paths for srp', () => {
        mockHostname('buy.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.logoSrc).toBe('/brands/srp/android-chrome-192x192.png');
    });

    it('returns features from brand config', () => {
        mockHostname('portal.localhost');
        const { result } = renderHook(() => useBrand());
        expect(result.current.features).toBeDefined();
    });

    it('handles case-insensitive hostname matching', () => {
        mockHostname('BUY.LOCALHOST');
        const { result } = renderHook(() => useBrand());
        expect(result.current.brand).toBe('srp');
    });
});
