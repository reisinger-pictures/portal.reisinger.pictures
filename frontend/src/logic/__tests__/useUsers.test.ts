import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import useSWR from 'swr';
import { apiMutate } from '../../api';
import { useUsers } from '../useUsers';

vi.mock('swr', () => ({
    default: vi.fn(),
    mutate: vi.fn(),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
    fetcher: vi.fn(),
}));

const mutateUsers = vi.fn();

function renderUsers() {
    vi.mocked(useSWR).mockReturnValue({
        data: {data: []},
        error: undefined,
        isLoading: false,
        mutate: mutateUsers,
    } as never);

    return renderHook(() => useUsers());
}

describe('useUsers.updateUser', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateUsers.mockResolvedValue(undefined);
        vi.mocked(apiMutate).mockResolvedValue({} as never);
    });

    // D-51/D-56: the server compares `expected_role_ids` with the role set it
    // reads under the shared FTP account lock. The editor must therefore send
    // the role set it loaded, or a stale form silently re-grants the camera
    // account instead of being refused with a 409.
    it('sends the loaded role set as expected_role_ids', async () => {
        const {result} = renderUsers();

        await act(async () => {
            await result.current.updateUser(
                'user-1',
                ['role-client'],
                ['role-client', 'role-photographer'],
                ['group-1'],
                ['gallery-1'],
                true,
                'web',
                'rp',
                true,
            );
        });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/users/user-1',
            'PUT',
            {
                role_ids: ['role-client', 'role-photographer'],
                expected_role_ids: ['role-client'],
                gallery_group_ids: ['group-1'],
                gallery_ids: ['gallery-1'],
                can_edit_metadata: true,
                flatrate_level: 'web',
                brand: 'rp',
                can_purchase_upgrades: true,
            },
        );
        expect(mutateUsers).toHaveBeenCalledTimes(1);
    });

    // A refused save must still refresh the list. Without this the editor
    // reopens from the same stale roles and every retry hits the same 409.
    it('refreshes the user list before rethrowing a failed save', async () => {
        const conflict = Object.assign(new Error('Rollen wurden inzwischen geändert.'), {status: 409});
        vi.mocked(apiMutate).mockRejectedValue(conflict);
        const {result} = renderUsers();

        let thrown: unknown;
        await act(async () => {
            try {
                await result.current.updateUser(
                    'user-1',
                    ['role-photographer'],
                    ['role-client'],
                    [],
                    [],
                    false,
                    'none',
                    'rp',
                    false,
                );
            } catch (error: unknown) {
                thrown = error;
            }
        });

        expect(thrown).toBe(conflict);
        expect(mutateUsers).toHaveBeenCalledTimes(1);
    });
});
