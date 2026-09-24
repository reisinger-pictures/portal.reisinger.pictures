import {beforeEach, describe, expect, it, vi} from 'vitest';
import {renderHook} from '@testing-library/react';
import useSWR from 'swr';
import type {CartItem} from '../CartContext';
import {useLicenseTerms} from '../useLicenseTerms';
import {useVolumeLicensing} from '../useVolumeLicensing';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../useLicenseTerms', () => ({
    useLicenseTerms: vi.fn(),
}));

const cartItems: CartItem[] = [
    {photoId: 'cart-photo-1', tier: 'original', galleryId: 'cart-gallery', price: 111},
    {photoId: 'cart-photo-2', tier: 'original', galleryId: 'cart-gallery', price: 222},
    {photoId: 'cart-photo-3', tier: 'original', galleryId: 'cart-gallery', price: 333},
    {photoId: 'quote-photo', tier: 'original', galleryId: 'cart-gallery', price: 0, isQuote: true},
];

function mockGalleryTerms(data: unknown) {
    vi.mocked(useSWR).mockReturnValue({
        data,
        error: undefined,
        isLoading: false,
        isValidating: false,
        mutate: vi.fn(),
    } as never);
}

describe('useVolumeLicensing gallery context', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: {pricing_strategy: 'scope_licensing'},
            isLoading: false,
            updateTerms: vi.fn(),
        });
    });

    it('uses the displayed gallery instead of the first cart gallery for a custom preset', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                preset_id: 'displayed-preset',
                preset_name: 'Displayed Preset',
                tiers: [
                    {min_quantity: 0, price_cents: 7000},
                    {min_quantity: 3, price_cents: 5000},
                ],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'displayed-gallery'));

        expect(result.current).toMatchObject({
            isVolumePricing: true,
            pricePerItemCents: 5000,
            totalCents: 15000,
            tierIndex: 1,
            isMaxTier: true,
        });
        expect(vi.mocked(useSWR).mock.calls.map(([key]) => key)).toContain(
            '/api/settings/license-terms?gallery_id=displayed-gallery',
        );
        expect(vi.mocked(useSWR).mock.calls.map(([key]) => key)).not.toContain(
            '/api/settings/license-terms?gallery_id=cart-gallery',
        );
    });

    it('honors a gallery scope override when the brand default is volume', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: {pricing_strategy: 'volume_licensing'},
            isLoading: false,
            updateTerms: vi.fn(),
        });
        mockGalleryTerms({
            pricing_strategy: 'scope_licensing',
            volume_pricing: null,
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'scope-gallery'));

        expect(result.current).toMatchObject({
            isVolumePricing: false,
            totalCents: 0,
        });
    });

    it('honors a gallery volume override and custom preset when the brand default is scope', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                preset_id: 'volume-override',
                preset_name: 'Volume Override',
                tiers: [{min_quantity: 0, price_cents: 6200}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'volume-gallery'));

        expect(result.current).toMatchObject({
            isVolumePricing: true,
            pricePerItemCents: 6200,
            totalCents: 18600,
            tierIndex: 0,
            isMaxTier: true,
        });
    });

    it('retains the cart-gallery fallback for cart-level consumers', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                tiers: [{min_quantity: 0, price_cents: 4100}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems));

        expect(result.current.pricePerItemCents).toBe(4100);
        expect(vi.mocked(useSWR).mock.calls.every(
            ([key]) => key === '/api/settings/license-terms?gallery_id=cart-gallery',
        )).toBe(true);
    });
});
