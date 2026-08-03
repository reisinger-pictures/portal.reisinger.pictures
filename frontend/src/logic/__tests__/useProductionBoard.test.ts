import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useProductionBoard } from '../useProductionBoard';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate, fetcher } from '../../api';

const owner = { id: 'u1', name: 'Florian' };
const mockJobs = {
    photo_jobs: [
        { id: 'j1', status: 'shooting', position: 0, owner, assignee: null, created_at: '2026-08-02T10:00:00Z', title: 'Hochzeit Müller', lightroom_catalog: '2026-08', lightroom_catalog_is_mine: true, total_count: 1200, selected_count: 400, target_gallery_id: null },
    ],
};

describe('useProductionBoard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('uses the photo-jobs endpoint and exposes jobs', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockJobs,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useProductionBoard());
        expect(useSWR).toHaveBeenCalledWith('/api/management/photo-jobs', fetcher);
        expect(result.current.photoJobs).toHaveLength(1);
        expect(result.current.photoJobs?.[0].title).toBe('Hochzeit Müller');
        expect(result.current.photoJobs?.[0].lightroom_catalog_is_mine).toBe(true);
    });

    it('shows loading state', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useProductionBoard());
        expect(result.current.photoJobs).toBeUndefined();
        expect(result.current.isLoading).toBe(true);
    });

    it('create posts and reloads', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({ photo_job: { id: 'new-1' } } as never);

        const { result } = renderHook(() => useProductionBoard());
        await result.current.create({ title: 'Neuer Job' });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/photo-jobs', 'POST', { title: 'Neuer Job' });
        expect(mutate).toHaveBeenCalled();
    });

    it('move patches status and position', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useProductionBoard());
        await result.current.move('j1', 'culling', 2);
        expect(apiMutate).toHaveBeenCalledWith('/api/management/photo-jobs/j1/move', 'PATCH', { status: 'culling', position: 2 });
        expect(mutate).toHaveBeenCalled();
    });

    it('update puts and remove deletes', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useProductionBoard());
        await result.current.update('j1', { title: 'X' });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/photo-jobs/j1', 'PUT', { title: 'X' });

        await result.current.remove('j1');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/photo-jobs/j1', 'DELETE');
    });

    it('move propagates api errors and does not reload', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValue(new Error('Netzwerkfehler'));

        const { result } = renderHook(() => useProductionBoard());
        await expect(result.current.move('j1', 'culling', 2)).rejects.toThrow('Netzwerkfehler');
        expect(mutate).not.toHaveBeenCalled();
    });

    it('remove propagates api errors and does not reload', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValue(new Error('Löschen fehlgeschlagen'));

        const { result } = renderHook(() => useProductionBoard());
        await expect(result.current.remove('j1')).rejects.toThrow('Löschen fehlgeschlagen');
        expect(mutate).not.toHaveBeenCalled();
    });
});