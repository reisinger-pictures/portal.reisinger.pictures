import {describe, it, expect, vi, beforeEach} from 'vitest';
import {renderHook, act} from '@testing-library/react';
import {useProtectedGalleries} from '../useGalleries';
import {apiMutate} from '../../api';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
    mutate: vi.fn(),
}));

vi.mock('../usePermissions', () => ({
    usePermissions: vi.fn(() => ({isAdmin: true, isPhotographer: false})),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
    fetcher: vi.fn(),
}));

describe('useProtectedGalleries group org assignment', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useSWR).mockReturnValue({
            data: {groups: [], root_galleries: []},
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);
    });

    it('sends org_id when creating a gallery group', async () => {
        const {result} = renderHook(() => useProtectedGalleries());

        await act(async () => {
            await result.current.createGroup('Ordner', 'ordner', true, null, {org_id: 'org-1', is_hidden: true});
        });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/gallery-groups',
            'POST',
            expect.objectContaining({org_id: 'org-1', is_hidden: true}),
        );
    });

    it('sends org_id when updating a gallery group', async () => {
        const {result} = renderHook(() => useProtectedGalleries());

        await act(async () => {
            await result.current.updateGroup('g-1', 'Ordner', 'ordner', true, null, {org_id: 'org-2'});
        });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/gallery-groups/g-1',
            'PUT',
            expect.objectContaining({org_id: 'org-2'}),
        );
    });

    it('does not synthesize org_id when an update omits the organisation field', async () => {
        const {result} = renderHook(() => useProtectedGalleries());

        await act(async () => {
            await result.current.updateGroup('g-1', 'Ordner', 'ordner', true, null, {is_hidden: true});
        });

        const updatePayload = vi.mocked(apiMutate).mock.calls[0][2];
        expect(updatePayload).not.toHaveProperty('org_id');
    });
});
