import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useGallery } from '../useGallery';
import { TRACKING_EVENTS } from '../tracking';

vi.mock('swr/infinite', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

import useSWRInfinite from 'swr/infinite';

const mockPage = {
    gallery: { id: 'g1', name: 'Test', slug: 'test', full_path: 'test', type: 'delivery', is_live: false, is_public: true },
    photos: [
        { id: 'p1', gallery_id: 'g1', filename: 'pic.jpg', lr_uuid: 'u1', url: '/pic.jpg', thumb_url: '/thumb.jpg', title: 'Pic', width: 800, height: 600, rating: 0, comment: '' },
    ],
    can_manage: true,
    current_page: 1,
    last_page: 1,
    total: 1,
    downloads_count: 0,
    notified_count: 0,
    wants_notifications: false,
    breadcrumbs: [],
};

describe('useGallery', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: vi.fn(),
        } as never);
    });

    it('returns gallery and photos from paginated data', () => {
        const { result } = renderHook(() => useGallery('test-gallery'));
        expect(result.current.gallery).toEqual(mockPage.gallery);
        expect(result.current.photos).toHaveLength(1);
        expect(result.current.photos[0].id).toBe('p1');
        expect(result.current.canManage).toBe(true);
        expect(result.current.totalPhotos).toBe(1);
    });

    it('returns empty photos array when data is undefined', () => {
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useGallery('test-gallery'));
        expect(result.current.photos).toEqual([]);
        expect(result.current.gallery).toBeUndefined();
    });

    it('returns isReachingEnd correctly when on last page', () => {
        const { result } = renderHook(() => useGallery('test-gallery'));
        expect(result.current.isReachingEnd).toBe(true);
    });

    it('returns isReachingEnd as false when not on last page', () => {
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [{ ...mockPage, current_page: 1, last_page: 3 }],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useGallery('test-gallery'));
        expect(result.current.isReachingEnd).toBe(false);
    });

    it('returns correct wantsNotifications and breadcrumbs', () => {
        const { result } = renderHook(() => useGallery('test-gallery'));
        expect(result.current.wantsNotifications).toBe(false);
        expect(result.current.breadcrumbs).toEqual([]);
    });

    it('handles ratePhoto optimistic update', async () => {
        const mockMutate = vi.fn();
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200 }));

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.ratePhoto('p1', 5, 'Great!');

        expect(mockMutate).toHaveBeenCalled();
    });

    it('tracks a photo_rated event when rating a photo', async () => {
        const originalTrackEvent = window.trackEvent;
        const trackSpy = vi.fn();
        window.trackEvent = trackSpy;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200 }));

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.ratePhoto('p1', 5, 'Great!');

        expect(trackSpy).toHaveBeenCalledWith(TRACKING_EVENTS.photo_rated, {
            photo_id: 'p1',
            rating: 5,
            has_comment: true,
        });

        window.trackEvent = originalTrackEvent;
    });

    it('tracks photo_rated without comment flag when comment is empty', async () => {
        const originalTrackEvent = window.trackEvent;
        const trackSpy = vi.fn();
        window.trackEvent = trackSpy;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200 }));

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.ratePhoto('p1', 3, '');

        expect(trackSpy).toHaveBeenCalledWith(TRACKING_EVENTS.photo_rated, {
            photo_id: 'p1',
            rating: 3,
            has_comment: false,
        });

        window.trackEvent = originalTrackEvent;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });
});
