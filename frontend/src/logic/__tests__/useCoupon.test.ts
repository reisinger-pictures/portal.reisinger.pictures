import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook, act, waitFor} from '@testing-library/react';
import useCoupon from '../useCoupon';

const VALID_RESPONSE = {
    valid: true,
    coupon: {
        id: 1,
        code: 'SAVE10',
        type: 'fixed' as const,
        value: 10,
        scope_type: 'global' as const,
    },
    discount_cents: 1000,
};

const EXPIRED_RESPONSE = {
    valid: false,
    error: 'This coupon has expired.',
};

const ERROR_RESPONSE = {
    valid: false,
    error: 'Rabattcode nicht gefunden.',
};

function mockFetchOnce(data: unknown, ok = true) {
    return vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
        ok,
        json: () => Promise.resolve(data),
    }));
}

describe('useCoupon', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('validate with valid code returns coupon + discount', async () => {
        mockFetchOnce(VALID_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });

        expect(result.current.isValid).toBe(true);
        expect(result.current.couponCode).toBe('SAVE10');
        expect(result.current.discount).toBe(1000);
        expect(result.current.error).toBeNull();
    });

    it('validate with invalid code returns error', async () => {
        mockFetchOnce(ERROR_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('INVALID');
        });

        expect(result.current.isValid).toBe(false);
        expect(result.current.couponCode).toBeNull();
        expect(result.current.error).toBe('Rabattcode nicht gefunden.');
    });

    it('validate with expired code returns error', async () => {
        mockFetchOnce(EXPIRED_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('EXPIRED');
        });

        expect(result.current.isValid).toBe(false);
        expect(result.current.couponCode).toBeNull();
        expect(result.current.error).toBe('This coupon has expired.');
    });

    it('apply coupon updates discount state', async () => {
        mockFetchOnce(VALID_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });

        expect(result.current.isValid).toBe(true);
        expect(result.current.discount).toBe(1000);
    });

    it('remove coupon clears state', async () => {
        mockFetchOnce(VALID_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });

        expect(result.current.isValid).toBe(true);

        act(() => {
            result.current.removeCoupon();
        });

        expect(result.current.isValid).toBe(false);
        expect(result.current.couponCode).toBeNull();
        expect(result.current.discount).toBeNull();
        expect(result.current.error).toBeNull();
    });

    it('coupon code passed in checkout payload', async () => {
        mockFetchOnce(VALID_RESPONSE);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });

        expect(result.current.couponCode).toBe('SAVE10');
        expect(result.current.isValid).toBe(true);
    });

    it('validate with gallery context passes params correctly', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(VALID_RESPONSE),
        });
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({
            galleryId: 42,
            metaGalleryId: 7,
            scopeGalleryId: 99,
        }));

        await act(async () => {
            await result.current.applyCoupon('GALLERY10');
        });

        expect(fetchMock).toHaveBeenCalledWith('/api/coupons/validate', expect.objectContaining({
            body: JSON.stringify({
                code: 'GALLERY10',
                gallery_id: 42,
                meta_gallery_id: 7,
                scope_gallery_id: 99,
            }),
        }));
    });

    it('loading state during validation', async () => {
        let resolvePromise: (v: unknown) => void;
        const fetchMock = vi.fn().mockReturnValue(new Promise((resolve) => {
            resolvePromise = resolve;
        }));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon());

        let promise: Promise<void>;
        act(() => {
            promise = result.current.applyCoupon('SLOW');
        });

        expect(result.current.isLoading).toBe(true);

        await act(async () => {
            resolvePromise!({ok: true, json: () => Promise.resolve(VALID_RESPONSE)});
            await promise;
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });
    });

    it('error state on API failure', async () => {
        const fetchMock = vi.fn().mockRejectedValue(new Error('Network error'));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('FAIL');
        });

        expect(result.current.isValid).toBe(false);
        expect(result.current.error).toBe('Netzwerkfehler: Rabattcode konnte nicht geprüft werden.');
        expect(result.current.isLoading).toBe(false);
    });

    it('applies coupon with scope_gallery_id and gallery_id options', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(VALID_RESPONSE),
        });
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({
            galleryId: 10,
            scopeGalleryId: 20,
        }));

        await act(async () => {
            await result.current.applyCoupon('SCOPE10');
        });

        expect(fetchMock).toHaveBeenCalledWith('/api/coupons/validate', expect.objectContaining({
            body: JSON.stringify({
                code: 'SCOPE10',
                gallery_id: 10,
                scope_gallery_id: 20,
            }),
        }));
        expect(result.current.isValid).toBe(true);
    });

    it('applies coupon without gallery context (global scope)', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(VALID_RESPONSE),
        });
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon());

        await act(async () => {
            await result.current.applyCoupon('GLOBAL10');
        });

        expect(fetchMock).toHaveBeenCalledWith('/api/coupons/validate', expect.objectContaining({
            body: JSON.stringify({
                code: 'GLOBAL10',
            }),
        }));
        expect(result.current.isValid).toBe(true);
        expect(result.current.couponCode).toBe('SAVE10');
        expect(result.current.discount).toBe(1000);
    });
});
