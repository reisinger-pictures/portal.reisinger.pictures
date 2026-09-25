import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useGallery } from '../useGallery';

vi.mock('swr/infinite', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
    fetcher: vi.fn(),
}));

import useSWRInfinite from 'swr/infinite';
import {apiMutate} from '../../api';

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
    const originalLocation = window.location;

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(apiMutate).mockResolvedValue({});
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: {replace: vi.fn()},
        });

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

    it('applies a ratePhoto optimistic update and sends the mutation', async () => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.ratePhoto('p1', 5, 'Great!');

        expect(apiMutate).toHaveBeenCalledWith('/api/photos/p1/rate', 'POST', {rating: 5, comment: 'Great!'});
        expect(mockMutate).toHaveBeenCalledTimes(1);
        expect(mockMutate.mock.calls[0][0][0].photos[0]).toMatchObject({rating: 5, comment: 'Great!'});
        expect(mockMutate.mock.calls[0][1]).toEqual({revalidate: false});
    });

    it.each([403, 422, 500])('rolls back the optimistic update and surfaces a %s response', async (status) => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);
        const apiError = Object.assign(new Error(`Rating failed with ${status}`), {status});
        vi.mocked(apiMutate).mockRejectedValueOnce(apiError);

        const { result } = renderHook(() => useGallery('test-gallery'));

        await expect(result.current.ratePhoto('p1', 5, 'Great!')).rejects.toBe(apiError);
        expect(mockMutate).toHaveBeenCalledTimes(2);
        expect(mockMutate.mock.calls[1][0]).toEqual([mockPage]);
        expect(mockMutate.mock.calls[1][1]).toEqual({revalidate: false});
    });

    it('preserves the rating API error when cache rollback itself fails', async () => {
        const mockMutate = vi.fn()
            .mockResolvedValueOnce(undefined)
            .mockRejectedValueOnce(new Error('cache rollback failed'));
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);
        const apiError = Object.assign(new Error('Rating failed with 500'), {status: 500});
        vi.mocked(apiMutate).mockRejectedValueOnce(apiError);

        const { result } = renderHook(() => useGallery('test-gallery'));

        await expect(result.current.ratePhoto('p1', 5, 'Great!')).rejects.toBe(apiError);
        expect(mockMutate).toHaveBeenCalledTimes(2);
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('rolls back and rethrows a transient rating transport failure without redirecting', async () => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);
        const apiError = Object.assign(new Error('Rating service temporarily unavailable'), {status: 0});
        vi.mocked(apiMutate).mockRejectedValueOnce(apiError);

        const { result } = renderHook(() => useGallery('test-gallery'));

        await expect(result.current.ratePhoto('p1', 5, 'Great!')).rejects.toBe(apiError);
        expect(mockMutate).toHaveBeenCalledTimes(2);
        expect(mockMutate.mock.calls[1][0]).toEqual([mockPage]);
        expect(mockMutate.mock.calls[1][1]).toEqual({revalidate: false});
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('rolls back and opens the real authentication flow after a 401', async () => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValueOnce(Object.assign(new Error('Unauthenticated'), {status: 401}));

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.ratePhoto('p1', 5, 'Great!');

        expect(mockMutate).toHaveBeenCalledTimes(2);
        expect(mockMutate.mock.calls[1][0]).toEqual([mockPage]);
        expect(window.location.replace).toHaveBeenCalledWith('/');
    });

    it('routes notification opt-in through the shared mutation and revalidates after success', async () => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);

        const { result } = renderHook(() => useGallery('test-gallery'));
        await result.current.toggleOptIn('g1', true);

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/galleries/g1/opt-in',
            'POST',
            {wants_notifications: true},
        );
        expect(mockMutate).toHaveBeenCalledWith();
    });

    it('does not revalidate notification opt-in when the shared mutation fails', async () => {
        const mockMutate = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useSWRInfinite).mockReturnValue({
            data: [mockPage],
            error: undefined,
            isLoading: false,
            isValidating: false,
            size: 1,
            setSize: vi.fn(),
            mutate: mockMutate,
        } as never);
        const apiError = Object.assign(new Error('Opt-in failed'), {status: 500});
        vi.mocked(apiMutate).mockRejectedValueOnce(apiError);

        const { result } = renderHook(() => useGallery('test-gallery'));
        await expect(result.current.toggleOptIn('g1', false)).rejects.toBe(apiError);
        expect(mockMutate).not.toHaveBeenCalled();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: originalLocation,
        });
    });
});
