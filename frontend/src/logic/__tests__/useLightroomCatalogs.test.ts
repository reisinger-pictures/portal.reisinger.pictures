import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useLightroomCatalogs } from '../useLightroomCatalogs';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate, fetcher } from '../../api';

const mockCatalogs = {
    lightroom_catalogs: [
        { id: 'c1', name: '2026-08', position: 0 },
        { id: 'c2', name: '2026-07', position: 1 },
    ],
};

describe('useLightroomCatalogs', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('uses the lightroom-catalogs endpoint and exposes catalogs', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockCatalogs,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLightroomCatalogs());
        expect(useSWR).toHaveBeenCalledWith('/api/management/lightroom-catalogs', fetcher);
        expect(result.current.lightroomCatalogs).toHaveLength(2);
        expect(result.current.lightroomCatalogs?.[0].name).toBe('2026-08');
    });

    it('create posts name and reloads', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useLightroomCatalogs());
        await result.current.create('2026-09');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/lightroom-catalogs', 'POST', { name: '2026-09' });
        expect(mutate).toHaveBeenCalled();
    });

    it('update puts name and reloads', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useLightroomCatalogs());
        await result.current.update('c1', '2026-08');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/lightroom-catalogs/c1', 'PUT', { name: '2026-08' });
        expect(mutate).toHaveBeenCalled();
    });

    it('remove deletes and reloads', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useLightroomCatalogs());
        await result.current.remove('c1');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/lightroom-catalogs/c1', 'DELETE');
        expect(mutate).toHaveBeenCalled();
    });

    it('create propagates api errors and does not reload', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValue(new Error('Speichern fehlgeschlagen'));

        const { result } = renderHook(() => useLightroomCatalogs());
        await expect(result.current.create('X')).rejects.toThrow('Speichern fehlgeschlagen');
        expect(mutate).not.toHaveBeenCalled();
    });
});
