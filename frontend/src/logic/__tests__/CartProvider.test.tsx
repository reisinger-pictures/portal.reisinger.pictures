import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { CartProvider } from '../CartProvider';
import { useCart } from '../CartContext';
import { useAuth } from '../useAuth';
import { useUI } from '../../ui/components/UIContext';
import { useVolumeLicensing } from '../useVolumeLicensing';

vi.mock('../useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../ui/components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../useVolumeLicensing', () => ({
    useVolumeLicensing: vi.fn(),
}));

const user = {
    id: 'u1',
    name: 'Test User',
    email: 'user@example.com',
    roles: [],
};

const item = {
    photoId: 'p1',
    tier: 'original' as const,
    price: 75000,
};

const cartKey = `rp_cart_${btoa(user.id)}`;

function wrapper({children}: {children: ReactNode}) {
    return <CartProvider>{children}</CartProvider>;
}

describe('CartProvider quote metadata', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.mocked(useAuth).mockReturnValue({user} as never);
        vi.mocked(useUI).mockReturnValue({showToast: vi.fn()} as never);
        vi.mocked(useVolumeLicensing).mockReturnValue({
            isVolumePricing: false,
            tierIndex: 0,
            isMaxTier: false,
            pricePerItemCents: 75000,
            totalCents: 0,
            nextTierCount: 0,
            nextTierLabel: '',
            tiers: [],
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('uses grouped server totals instead of the default volume preset total', async () => {
        const mixedItems = [
            {...item, photoId: 'scope-1', galleryId: 'scope-gallery', price: 500},
            {...item, photoId: 'volume-1', galleryId: 'volume-gallery', price: 0},
            {...item, photoId: 'volume-2', galleryId: 'volume-gallery', price: 0},
        ];
        localStorage.setItem(cartKey, JSON.stringify(mixedItems));
        vi.mocked(useVolumeLicensing).mockReturnValue({
            isVolumePricing: true,
            tierIndex: 1,
            isMaxTier: true,
            pricePerItemCents: 4000,
            totalCents: 8000,
            nextTierCount: 0,
            nextTierLabel: '',
            tiers: [{minQuantity: 0, priceCents: 5000}, {minQuantity: 2, priceCents: 4000}],
            groupedTotalCents: 8500,
            volumeSubtotalCents: 8000,
        });

        const {result} = renderHook(() => useCart(), {wrapper});

        await waitFor(() => expect(result.current.totalAmount).toBe(8500));
    });

    it('rehydrates the signed quote token and persists quote metadata safely', async () => {
        const quoteToken = 'header.payload.signature';
        localStorage.setItem(cartKey, JSON.stringify({version: 1, items: [item], quoteToken}));

        const {result} = renderHook(() => useCart(), {wrapper});

        await waitFor(() => expect(result.current.quoteToken).toBe(quoteToken));
        expect(result.current.items).toEqual([item]);

        act(() => result.current.setQuoteToken(null));

        await waitFor(() => {
            expect(JSON.parse(localStorage.getItem(cartKey) ?? 'null')).toEqual([item]);
        });
    });

    it('clears a quote token when the last item is removed', async () => {
        const quoteToken = 'header.payload.signature';
        localStorage.setItem(cartKey, JSON.stringify({version: 1, items: [item], quoteToken}));

        const {result} = renderHook(() => useCart(), {wrapper});
        await waitFor(() => expect(result.current.quoteToken).toBe(quoteToken));

        act(() => result.current.removeFromCart(item.photoId));

        await waitFor(() => expect(result.current.quoteToken).toBeNull());
        expect(result.current.items).toEqual([]);
    });

    it('invalidates a quote token when one item is removed from a multi-item quote and persists the remaining item', async () => {
        const quoteToken = 'header.payload.signature';
        const secondItem = {...item, photoId: 'p2'};
        localStorage.setItem(cartKey, JSON.stringify({version: 1, items: [item, secondItem], quoteToken}));

        const {result, unmount} = renderHook(() => useCart(), {wrapper});
        await waitFor(() => expect(result.current.quoteToken).toBe(quoteToken));

        act(() => result.current.removeFromCart(item.photoId));

        await waitFor(() => {
            expect(result.current.quoteToken).toBeNull();
            expect(result.current.items).toEqual([secondItem]);
        });
        await waitFor(() => {
            expect(JSON.parse(localStorage.getItem(cartKey) ?? 'null')).toEqual([secondItem]);
        });

        unmount();
        const {result: reloaded} = renderHook(() => useCart(), {wrapper});
        await waitFor(() => expect(reloaded.current.quoteToken).toBeNull());
        expect(reloaded.current.items).toEqual([secondItem]);
    });

    it('clears a quote token when a new item replaces the signed photo set', async () => {
        const quoteToken = 'header.payload.signature';
        localStorage.setItem(cartKey, JSON.stringify({version: 1, items: [item], quoteToken}));

        const {result} = renderHook(() => useCart(), {wrapper});
        await waitFor(() => expect(result.current.quoteToken).toBe(quoteToken));

        act(() => result.current.addToCart({...item, photoId: 'p2'}));

        await waitFor(() => expect(result.current.quoteToken).toBeNull());
        expect(result.current.items.map(cartItem => cartItem.photoId)).toEqual(['p2']);
    });
});
