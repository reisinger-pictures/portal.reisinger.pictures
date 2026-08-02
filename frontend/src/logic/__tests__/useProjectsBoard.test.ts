import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useProjectsBoard } from '../useProjectsBoard';

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
const mockProjects = {
    projects: [
        { id: 'p1', status: 'anfrage', position: 0, owner, assignee: null, created_at: '2026-08-02T10:00:00Z', client_name: 'Muster GmbH', email: 'info@muster.de', phone: null, package: null, price_cents: 0, payment_status: 'open' },
        { id: 'p2', status: 'beauftragt', position: 0, owner, assignee: { id: 'u2', name: 'Max' }, created_at: '2026-08-01T10:00:00Z', client_name: 'Beispiel AG', email: '', phone: '0123', package: 'Hochzeit', price_cents: 150000, payment_status: 'paid' },
    ],
};

describe('useProjectsBoard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('uses the projects endpoint and exposes projects', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockProjects,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useProjectsBoard());
        expect(useSWR).toHaveBeenCalledWith('/api/management/projects', fetcher);
        expect(result.current.projects).toHaveLength(2);
        expect(result.current.projects?.[0].client_name).toBe('Muster GmbH');
    });

    it('shows loading state', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useProjectsBoard());
        expect(result.current.projects).toBeUndefined();
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

        const { result } = renderHook(() => useProjectsBoard());
        await result.current.create({ client_name: 'Neu GmbH' });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/projects', 'POST', { client_name: 'Neu GmbH' });
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

        const { result } = renderHook(() => useProjectsBoard());
        await result.current.move('p1', 'angebot', 0);
        expect(apiMutate).toHaveBeenCalledWith('/api/management/projects/p1/move', 'PATCH', { status: 'angebot', position: 0 });
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

        const { result } = renderHook(() => useProjectsBoard());
        await result.current.update('p1', { client_name: 'X' });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/projects/p1', 'PUT', { client_name: 'X' });

        await result.current.remove('p1');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/projects/p1', 'DELETE');
    });
});