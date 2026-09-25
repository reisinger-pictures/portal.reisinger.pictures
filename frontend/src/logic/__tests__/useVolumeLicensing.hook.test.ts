import {beforeEach, describe, expect, it, vi} from 'vitest';
import {renderHook} from '@testing-library/react';
import useSWR from 'swr';
import type {CartItem} from '../CartContext';
import {useLicenseTerms} from '../useLicenseTerms';
import {useGalleryLicensing, useVolumeLicensing} from '../useVolumeLicensing';

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

    it('uses the displayed gallery descriptor for the prospective card price', () => {
        mockGalleryTerms({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
            'displayed-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 31,
                    preset_name: 'Displayed Preset',
                    tiers: [
                        {min_quantity: 0, price_cents: 7000},
                        {min_quantity: 3, price_cents: 5000},
                    ],
                },
            },
        });

        const mixedItems: CartItem[] = [
            ...cartItems,
            ...cartItems.map(item => ({...item, galleryId: 'displayed-gallery'})),
        ];
        const {result} = renderHook(() => useVolumeLicensing(mixedItems, 'displayed-gallery'));

        expect(result.current).toMatchObject({
            isVolumePricing: true,
            pricePerItemCents: 5000,
            totalCents: 15000,
            tierIndex: 1,
            isMaxTier: true,
        });
        expect(vi.mocked(useSWR).mock.calls.map(([key]) => key)).toContain(
            '__cart_license_terms__:%5B%22cart-gallery%22%2C%22displayed-gallery%22%5D',
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
            'cart-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 41,
                    tiers: [{min_quantity: 0, price_cents: 3000}],
                },
            },
            'scope-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'scope-gallery'));

        expect(result.current).toMatchObject({
            isVolumePricing: false,
            totalCents: 0,
        });
    });

    it('honors a gallery volume override and custom preset when the brand default is scope', () => {
        mockGalleryTerms({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
            'volume-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 51,
                    preset_name: 'Volume Override',
                    tiers: [{min_quantity: 0, price_cents: 6200}],
                },
            },
        });

        const volumeItems: CartItem[] = [
            ...cartItems.map(item => ({...item, galleryId: 'volume-gallery'})),
            {photoId: 'scope-photo', tier: 'original', galleryId: 'cart-gallery', price: 500},
        ];
        const {result} = renderHook(() => useVolumeLicensing(volumeItems, 'volume-gallery'));

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
                preset_id: null,
                tiers: [{min_quantity: 0, price_cents: 4100}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems));

        expect(result.current.pricePerItemCents).toBe(4100);
        expect(vi.mocked(useSWR).mock.calls.every(
            ([key]) => key === '/api/settings/license-terms?gallery_id=cart-gallery',
        )).toBe(true);
    });

    // Regression: the API delivers `volume_pricing.preset_id` as the numeric
    // `volume_presets.id` primary key. Calling `.trim()` on it threw
    // "volumePricing?.preset_id?.trim is not a function", which unmounted the
    // whole photo page behind the ErrorBoundary.
    it('resolves a numeric preset id without throwing and keys the group by its decimal form', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                preset_id: 7,
                preset_name: 'Werbung',
                tiers: [{min_quantity: 0, price_cents: 4000}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'cart-gallery'));

        expect(result.current).toMatchObject({isVolumePricing: true, pricePerItemCents: 4000});
        expect(result.current.groups?.map(group => [group.presetId, group.presetName])).toEqual([
            ['7', 'Werbung'],
        ]);
    });

    it('still resolves a stringified preset id from a stringifying intermediary', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                preset_id: '7',
                preset_name: 'Werbung',
                tiers: [{min_quantity: 0, price_cents: 4000}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'cart-gallery'));

        expect(result.current.groups?.map(group => group.presetId)).toEqual(['7']);
    });

    it('falls back to the default preset key for a malformed provider payload', () => {
        mockGalleryTerms({
            pricing_strategy: 'volume_licensing',
            volume_pricing: {
                preset_id: {id: 7},
                preset_name: 12,
                tiers: [null, {min_quantity: 0, price_cents: 4000}],
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'cart-gallery'));

        expect(result.current).toMatchObject({isVolumePricing: true, pricePerItemCents: 4000});
        expect(result.current.groups?.map(group => [group.presetId, group.presetName])).toEqual([
            ['default', null],
        ]);
    });

    it('ignores a non-object terms response instead of throwing', () => {
        mockGalleryTerms('volume_licensing');

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'cart-gallery'));

        expect(result.current.isVolumePricing).toBe(false);
        expect(result.current.pricePerItemCents).toBe(0);
    });

    it('keeps mixed child groups independent even when a displayed gallery is supplied', () => {
        mockGalleryTerms({
            'gallery-a': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 1,
                    preset_name: 'Preset A',
                    tiers: [{min_quantity: 0, price_cents: 5000}, {min_quantity: 2, price_cents: 4000}],
                },
            },
            'gallery-b': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 2,
                    preset_name: 'Preset B',
                    tiers: [{min_quantity: 0, price_cents: 7000}],
                },
            },
        });
        const mixedItems: CartItem[] = [
            {photoId: 'a-1', tier: 'original', galleryId: 'gallery-a', galleryGroupId: 'group-a', price: 0},
            {photoId: 'b-1', tier: 'original', galleryId: 'gallery-b', galleryGroupId: 'group-b', price: 0},
            {photoId: 'b-2', tier: 'original', galleryId: 'gallery-b', galleryGroupId: 'group-b', price: 0},
        ];

        const {result} = renderHook(() => useVolumeLicensing(mixedItems, 'gallery-a'));

        expect(result.current.groups?.map(group => [group.presetId, group.totalCents])).toEqual([
            ['1', 5000],
            ['2', 14000],
        ]);
        expect(result.current.groupedTotalCents).toBe(19000);
        expect(result.current.volumeSubtotalCents).toBe(19000);
        // Gallery B's two items must not advance gallery A's retroactive tier.
        expect(result.current.totalCents).toBe(5000);
        expect(result.current.pricePerItemCents).toBe(5000);
        expect(result.current.tierIndex).toBe(0);
        expect(result.current.isMaxTier).toBe(false);
    });

    it('does not leak a partial child response when the composite terms map is incomplete', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: {pricing_strategy: 'volume_licensing'},
            isLoading: false,
            updateTerms: vi.fn(),
        });
        mockGalleryTerms({
            'gallery-a': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
        });

        const {result} = renderHook(() => useGalleryLicensing([
            {galleryId: 'gallery-a', galleryGroupId: 'group-a', photoCount: 1},
            {galleryId: 'gallery-b', galleryGroupId: 'group-b', photoCount: 1},
        ]));

        expect(result.current.groups).toHaveLength(1);
        expect(result.current.groups[0]).toMatchObject({
            licensingMode: 'volume_licensing',
            presetId: 'default',
            galleryIds: ['gallery-a', 'gallery-b'],
            photoCount: 2,
            totalCents: 6000,
        });
    });

    it('ignores a partial descriptor instead of applying it to displayed or cart groups', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: {pricing_strategy: 'volume_licensing'},
            isLoading: false,
            updateTerms: vi.fn(),
        });
        mockGalleryTerms({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
        });

        const {result} = renderHook(() => useVolumeLicensing(cartItems, 'displayed-gallery'));

        expect(result.current.isVolumePricing).toBe(true);
        expect(result.current.pricePerItemCents).toBe(3000);
        expect(result.current.groups?.map(group => [
            group.licensingMode,
            group.presetId,
        ])).toEqual([['volume_licensing', 'default']]);
    });

    it('resolves meta-gallery child groups independently and totals each preset', () => {
        mockGalleryTerms({
            'gallery-a': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 1,
                    preset_name: 'Preset A',
                    tiers: [{min_quantity: 0, price_cents: 5000}, {min_quantity: 2, price_cents: 4000}],
                },
            },
            'gallery-b': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 2,
                    preset_name: 'Preset B',
                    tiers: [{min_quantity: 0, price_cents: 7000}],
                },
            },
            'gallery-scope': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
        });

        const {result} = renderHook(() => useGalleryLicensing([
            {galleryId: 'gallery-a', galleryGroupId: 'group-a', photoCount: 2},
            {galleryId: 'gallery-b', galleryGroupId: 'group-b', photoCount: 1},
            {galleryId: 'gallery-scope', galleryGroupId: 'group-scope', photoCount: 1},
        ]));

        expect(result.current.isVolumePricing).toBe(true);
        expect(result.current.groups.map(group => [group.presetId, group.totalCents])).toEqual([
            ['1', 8000],
            ['2', 7000],
            ['default', null],
        ]);
        expect(result.current.volumeSubtotalCents).toBe(15000);
    });
});
