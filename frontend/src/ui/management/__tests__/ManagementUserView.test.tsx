import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { renderWithProviders } from '../../../test-setup';
import { UIContext, type UIContextType } from '../../components/UIContext';
import { useUsers, UserRole, type UserDetailed } from '../../../logic/useUsers';
import { usePermissions, type Permissions } from '../../../logic/usePermissions';
import { useProtectedGalleries, type GalleryTreeResponse } from '../../../logic/useGalleries';
import type { Gallery } from '../../../api';
import ManagementUserView from '../ManagementUserView';

// SEC-20261018-02: the server rejects gallery/group assignments outside the
// organization's delegation scope. This view is the user editor, so it must not
// offer them to an org admin. The tree it receives is permission-filtered but
// also contains the actor's personal grants; the lists are therefore narrowed
// with the server-resolved `org_scope` and the modal is the observable surface.

vi.mock('../../../logic/useUsers', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useUsers')>()),
    useUsers: vi.fn(),
}));

vi.mock('../../../logic/usePermissions', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/usePermissions')>()),
    usePermissions: vi.fn(),
}));

vi.mock('../../../logic/useGalleries', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useGalleries')>()),
    useProtectedGalleries: vi.fn(),
}));

const target: UserDetailed = {
    id: 'user-1',
    name: 'Max Mustermann',
    email: 'max@example.com',
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    flatrate_level: 'none',
    roles: [],
    gallery_groups: [],
    galleries: [],
};

function gallery(id: string, name: string): Gallery {
    return {
        id,
        name,
        slug: id,
        full_path: name,
        type: 'delivery',
        is_live: false,
        is_public: false,
    };
}

const tree: GalleryTreeResponse = {
    groups: [
        {
            id: 'g1',
            name: 'Print',
            parent_id: null,
            galleries: [gallery('ga1', 'Delivery A')],
        },
        {
            id: 'g2',
            name: 'Hochzeit',
            parent_id: null,
            galleries: [gallery('ga2', 'Delivery B')],
        },
    ],
    root_galleries: [gallery('ga3', 'Root C')],
};

const basePermissions: Permissions = {
    isStaff: true,
    isSuperAdmin: false,
    isAdmin: true,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: true,
    canAccessProductionBoard: false,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: false,
    orgScope: null,
};

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

function renderView() {
    return renderWithProviders(
        <MemoryRouter>
            <UIContext.Provider value={uiContext}>
                <ManagementUserView />
            </UIContext.Provider>
        </MemoryRouter>,
    );
}

async function openEditor(eventUser: ReturnType<typeof userEvent.setup>) {
    await eventUser.click(screen.getByRole('button', { name: 'Bearbeiten' }));
    return screen.getByRole('dialog', { name: 'Max Mustermann bearbeiten' });
}

describe('ManagementUserView delegation boundary', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useUsers).mockReturnValue({
            users: [target],
            roles: [{ id: 'role-client', name: UserRole.CLIENT }],
            createUser: vi.fn().mockResolvedValue(undefined),
            updateUser: vi.fn().mockResolvedValue(undefined),
            deleteUser: vi.fn().mockResolvedValue(undefined),
        });

        vi.mocked(useProtectedGalleries).mockReturnValue({
            tree,
            isLoading: false,
            isError: undefined,
            mutate: vi.fn().mockResolvedValue(undefined),
            createGroup: vi.fn().mockResolvedValue(undefined),
            createGallery: vi.fn().mockResolvedValue(undefined),
            updateGroup: vi.fn().mockResolvedValue(undefined),
            updateGallery: vi.fn().mockResolvedValue(undefined),
            deleteGroup: vi.fn().mockResolvedValue(undefined),
            deleteGallery: vi.fn().mockResolvedValue(undefined),
        });

        vi.mocked(usePermissions).mockReturnValue(basePermissions);
    });

    it('offers an org admin only galleries and groups inside the organisation scope', async () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isAdmin: false,
            isOrgAdmin: true,
            orgScope: { gallery_ids: ['ga1'], gallery_group_ids: ['g1'] },
        });

        const eventUser = userEvent.setup();
        renderView();
        const dialog = await openEditor(eventUser);

        expect(within(dialog).getByRole('checkbox', { name: 'Print' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Delivery A' })).toBeInTheDocument();

        expect(within(dialog).queryByRole('checkbox', { name: 'Hochzeit' })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('checkbox', { name: 'Delivery B' })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('checkbox', { name: 'Root C' })).not.toBeInTheDocument();
    });

    it('fails closed for an org admin without a server-resolved scope', async () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isAdmin: false,
            isOrgAdmin: true,
            orgScope: null,
        });

        const eventUser = userEvent.setup();
        renderView();
        const dialog = await openEditor(eventUser);

        expect(within(dialog).queryByRole('checkbox', { name: 'Print' })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('checkbox', { name: 'Delivery A' })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('checkbox', { name: 'Root C' })).not.toBeInTheDocument();
    });

    it('keeps the full assignment lists for an admin', async () => {
        const eventUser = userEvent.setup();
        renderView();
        const dialog = await openEditor(eventUser);

        expect(within(dialog).getByRole('checkbox', { name: 'Print' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Hochzeit' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Delivery A' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Delivery B' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Root C' })).toBeInTheDocument();
    });
});
