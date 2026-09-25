import {describe, expect, it, vi, beforeEach} from 'vitest';
import {renderHook} from '@testing-library/react';
import {useLocations} from '../useLocations';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

import useSWR from 'swr';

const locations = [{
    id: 'vienna',
    type: 'city' as const,
    name: 'Wien',
    state: null,
    country: 'AT',
    iso_country: 'AT',
}];

describe('useLocations', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('does not retain previous locations when the query becomes too short', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: locations,
            error: new Error('stale'),
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const {result} = renderHook(() => useLocations('a', 'city'));

        expect(result.current.locations).toEqual([]);
        expect(result.current.isLoading).toBe(false);
        expect(result.current.isError).toBeUndefined();
    });

    it('requests locations for a sufficiently long query', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: locations,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderHook(() => useLocations('Wien', 'city'));

        expect(useSWR).toHaveBeenCalledWith(
            '/api/search/locations?q=Wien&type=city',
            expect.any(Function),
        );
    });
});
