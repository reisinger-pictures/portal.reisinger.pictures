import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useSearch } from '../useSearch';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

import useSWR from 'swr';

const mockResults = {
    galleries: [{ id: 'g1', name: 'Gallery 1', slug: 'g1', full_path: 'g1', type: 'delivery', is_live: false, is_public: true }],
    photos: [{ id: 'p1', gallery_id: 'g1', filename: 'pic.jpg', lr_uuid: 'u1', url: '/pic.jpg', thumb_url: '/thumb.jpg', title: 'Pic', width: 800, height: 600, rating: 0, comment: '' }],
};

describe('useSearch', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('returns null key (no fetch) when query is empty and skipEmpty is true', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useSearch('', false, true));
        expect(result.current.results).toBeUndefined();
        expect(result.current.isLoading).toBe(false);
    });

    it('returns results when query is provided', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockResults,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useSearch('test', false, true));
        expect(result.current.results).toEqual(mockResults);
        expect(result.current.results?.galleries).toHaveLength(1);
        expect(result.current.results?.photos).toHaveLength(1);
    });

    it('includes personal=true in URL when personal flag is set', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderHook(() => useSearch('test', true));
        expect(useSWR).toHaveBeenCalledWith(
            '/api/search?q=test&personal=true',
            expect.any(Function),
            expect.any(Object),
        );
    });

    it('does not include personal param when not set', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderHook(() => useSearch('test'));
        expect(useSWR).toHaveBeenCalledWith(
            '/api/search?q=test',
            expect.any(Function),
            expect.any(Object),
        );
    });

    it('encodes query in URL', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderHook(() => useSearch('hello world'));
        expect(useSWR).toHaveBeenCalledWith(
            '/api/search?q=hello%20world',
            expect.any(Function),
            expect.any(Object),
        );
    });

    it('returns error state when search fails', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: new Error('Search failed'),
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useSearch('test'));
        expect(result.current.isError).toEqual(new Error('Search failed'));
        expect(result.current.results).toBeUndefined();
    });

    it('returns loading state while fetching', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useSearch('test'));
        expect(result.current.isLoading).toBe(true);
    });
});
