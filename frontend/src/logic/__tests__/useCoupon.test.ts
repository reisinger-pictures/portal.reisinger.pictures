import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook, act, waitFor} from '@testing-library/react';
import useCoupon, {calculateCouponDiscount} from '../useCoupon';

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
    return vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
        JSON.stringify(data),
        {
            status: ok ? 200 : 422,
            headers: {'Content-Type': 'application/json'},
        }
    )));
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

    it('refreshes once and retries coupon validation after a 401', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(null, {status: 200}))
            .mockResolvedValueOnce(new Response(
                JSON.stringify(VALID_RESPONSE),
                {status: 200, headers: {'Content-Type': 'application/json'}}
            ));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({galleryId: 42}));

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/api/coupons/validate',
            '/api/auth/refresh',
            '/api/coupons/validate',
        ]);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        const initialRequest = fetchMock.mock.calls[0][1] as RequestInit;
        const retryRequest = fetchMock.mock.calls[2][1] as RequestInit;
        expect(initialRequest.method).toBe('POST');
        expect(retryRequest.method).toBe('POST');
        expect(retryRequest.body).toBe(initialRequest.body);
        expect(result.current.isValid).toBe(true);
        expect(result.current.couponCode).toBe('SAVE10');
        expect(result.current.error).toBeNull();
    });

    it('validate with invalid code returns error', async () => {
        mockFetchOnce(ERROR_RESPONSE, false);
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

    it('uses max_items from the validation response for the percentage preview', async () => {
        mockFetchOnce({
            valid: true,
            coupon: {
                code: 'PARTIAL',
                type: 'percentage',
                value: 50,
                max_items: 2,
            },
            discount_cents: 5000,
        });
        const {result} = renderHook(() => useCoupon({
            pricedTotalCents: 10000,
            eligibleItemPricesCents: [5000, 3000, 2000],
        }));

        await act(async () => {
            await result.current.applyCoupon('PARTIAL');
        });

        expect(result.current.coupon?.max_items).toBe(2);
        expect(result.current.discount).toBe(2500);
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

    it('clears an applied coupon when eligibility or cart identity changes', async () => {
        mockFetchOnce(VALID_RESPONSE);
        const {result, rerender} = renderHook(
            ({enabled, validationContextKey}) => useCoupon({enabled, validationContextKey}),
            {initialProps: {enabled: true, validationContextKey: 'cart-a'}},
        );

        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });
        expect(result.current.couponCode).toBe('SAVE10');

        rerender({enabled: false, validationContextKey: 'cart-a'});
        expect(result.current.couponCode).toBeNull();
        expect(result.current.coupon).toBeNull();
        expect(result.current.isValid).toBe(false);
        expect(result.current.discount).toBeNull();

        mockFetchOnce(VALID_RESPONSE);
        rerender({enabled: true, validationContextKey: 'cart-a'});
        await act(async () => {
            await result.current.applyCoupon('SAVE10');
        });
        expect(result.current.couponCode).toBe('SAVE10');

        rerender({enabled: true, validationContextKey: 'cart-b'});
        expect(result.current.couponCode).toBeNull();
        expect(result.current.coupon).toBeNull();
        expect(result.current.isValid).toBe(false);
        expect(result.current.discount).toBeNull();
    });

    it('ignores an in-flight validation response after the cart identity changes', async () => {
        let resolveValidation: (response: Response) => void = () => undefined;
        vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise<Response>((resolve) => {
            resolveValidation = resolve;
        })));
        const {result, rerender} = renderHook(
            ({validationContextKey}) => useCoupon({validationContextKey}),
            {initialProps: {validationContextKey: 'cart-a'}},
        );

        let validationPromise: Promise<void>;
        act(() => {
            validationPromise = result.current.applyCoupon('SLOW');
        });
        expect(result.current.isLoading).toBe(true);

        rerender({validationContextKey: 'cart-b'});
        await act(async () => {
            resolveValidation(new Response(
                JSON.stringify(VALID_RESPONSE),
                {status: 200, headers: {'Content-Type': 'application/json'}},
            ));
            await validationPromise!;
        });

        expect(result.current.couponCode).toBeNull();
        expect(result.current.coupon).toBeNull();
        expect(result.current.isValid).toBe(false);
        expect(result.current.isLoading).toBe(false);
    });

    it('ignores an in-flight validation response after an A → B → A context change', async () => {
        let resolveValidation: (response: Response) => void = () => undefined;
        vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise<Response>((resolve) => {
            resolveValidation = resolve;
        })));
        const {result, rerender} = renderHook(
            ({validationContextKey}) => useCoupon({validationContextKey}),
            {initialProps: {validationContextKey: 'cart-a'}},
        );

        let validationPromise: Promise<void>;
        act(() => {
            validationPromise = result.current.applyCoupon('SLOW');
        });
        expect(result.current.isLoading).toBe(true);

        rerender({validationContextKey: 'cart-b'});
        rerender({validationContextKey: 'cart-a'});

        await act(async () => {
            resolveValidation(new Response(
                JSON.stringify(VALID_RESPONSE),
                {status: 200, headers: {'Content-Type': 'application/json'}},
            ));
            await validationPromise!;
        });

        expect(result.current.couponCode).toBeNull();
        expect(result.current.coupon).toBeNull();
        expect(result.current.isValid).toBe(false);
        expect(result.current.discount).toBeNull();
        expect(result.current.isLoading).toBe(false);
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
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(VALID_RESPONSE),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        ));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({
            galleryId: 42,
            metaGalleryId: 7,
        }));

        await act(async () => {
            await result.current.applyCoupon('GALLERY10');
        });

        expect(fetchMock).toHaveBeenCalledWith('/api/coupons/validate', expect.objectContaining({
            body: JSON.stringify({
                code: 'GALLERY10',
                gallery_id: '42',
                meta_gallery_id: '7',
            }),
        }));
    });

    it('sends every gallery and group id for mixed-scope validation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(VALID_RESPONSE),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        ));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({
            galleryId: ['gallery-b', 'gallery-a', 'gallery-b'],
            metaGalleryId: ['group-b', 'group-a'],
        }));

        await act(async () => {
            await result.current.applyCoupon('MIXED10');
        });

        const request = fetchMock.mock.calls[0][1] as RequestInit;
        expect(request.body).toBe(JSON.stringify({
            code: 'MIXED10',
            gallery_id: ['gallery-b', 'gallery-a'],
            meta_gallery_id: ['group-b', 'group-a'],
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
            resolvePromise!(new Response(
                JSON.stringify(VALID_RESPONSE),
                {status: 200, headers: {'Content-Type': 'application/json'}}
            ));
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

    it('applies coupon with gallery_id options', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(VALID_RESPONSE),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        ));
        vi.stubGlobal('fetch', fetchMock);
        const {result} = renderHook(() => useCoupon({
            galleryId: 10,
        }));

        await act(async () => {
            await result.current.applyCoupon('SCOPE10');
        });

        expect(fetchMock).toHaveBeenCalledWith('/api/coupons/validate', expect.objectContaining({
            body: JSON.stringify({
                code: 'SCOPE10',
                gallery_id: '10',
            }),
        }));
        expect(result.current.isValid).toBe(true);
    });

    it('applies coupon without gallery context (global scope)', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify(VALID_RESPONSE),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        ));
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

describe('calculateCouponDiscount', () => {
    it('uses the actual fixed-cart subtotal instead of the sample-cart response', () => {
        expect(calculateCouponDiscount({code: 'FIXED', type: 'fixed', value: 10}, 3500)).toBe(1000);
        expect(calculateCouponDiscount({code: 'FIXED', type: 'fixed', value: 99}, 3500)).toBe(3500);
    });

    it('uses the actual percentage-cart subtotal and supports a full discount', () => {
        expect(calculateCouponDiscount({code: 'PERCENT', type: 'percentage', value: 25}, 8000)).toBe(2000);
        expect(calculateCouponDiscount({code: 'FREE', type: 'percentage', value: 100}, 8000)).toBe(8000);
    });

    it('limits a percentage coupon to the configured cheapest items', () => {
        expect(calculateCouponDiscount(
            {code: 'PARTIAL', type: 'percentage', value: 50, max_items: 2},
            10000,
            [5000, 3000, 2000],
        )).toBe(2500);
    });
});
