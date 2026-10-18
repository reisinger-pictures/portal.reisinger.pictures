import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { renderWithProviders } from '../../../test-setup';
import { UIContext, type UIContextType } from '../../components/UIContext';
import { fetcher, type Gallery } from '../../../api';
import { useUsers, UserRole, type UserDetailed } from '../../../logic/useUsers';
import { usePermissions, type Permissions } from '../../../logic/usePermissions';
import type { GalleryTreeResponse } from '../../../logic/useGalleries';
import ManagementUserView from '../ManagementUserView';

vi.mock('../../../logic/useUsers', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useUsers')>()),
    useUsers: vi.fn(),
}));

vi.mock('../../../logic/usePermissions', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/usePermissions')>()),
    usePermissions: vi.fn(),
}));

vi.mock('../../../api', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../api')>()),
    fetcher: vi.fn(),
}));

const safeDelivery: Gallery = {
    id: 'delivery-1',
    name: 'Org delivery gallery',
    slug: 'org-delivery',
    full_path: 'galleries/org-delivery',
    type: 'delivery',
    is_live: false,
    is_public: false,
};

const assignmentOptions: GalleryTreeResponse = {
    groups: [{
        id: 'safe-group',
        name: 'Org delivery group',
        parent_id: null,
        galleries: [safeDelivery],
    }],
    root_galleries: [],
};

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

const permissions: Permissions = {
    isStaff: true,
    isSuperAdmin: false,
    isAdmin: false,
    isPhotographer: false,
    isOrgAdmin: true,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: false,
    canAccessProductionBoard: false,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: false,
    orgScope: {
        org_id: 'org-1',
        gallery_ids: ['delivery-1'],
        gallery_group_ids: ['safe-group'],
        assignable_gallery_group_ids: ['safe-group'],
    },
};

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

describe('ManagementUserView org-admin gallery options', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useUsers).mockReturnValue({
            users: [target],
            roles: [{ id: 'role-client', name: UserRole.CLIENT }],
            createUser: vi.fn().mockResolvedValue(undefined),
            updateUser: vi.fn().mockResolvedValue(undefined),
            deleteUser: vi.fn().mockResolvedValue(undefined),
        });
        vi.mocked(usePermissions).mockReturnValue(permissions);
        vi.mocked(fetcher).mockImplementation(async <T,>(url: string): Promise<T> => {
            if (url === '/api/management/orgs/org-1') {
                return { gallery_assignment_options: assignmentOptions } as T;
            }

            throw new Error(`Unexpected assignment-options request: ${url}`);
        });
    });

    it('loads only the org-scoped option endpoint and renders the returned delivery choices', async () => {
        const eventUser = userEvent.setup();

        renderWithProviders(
            <SWRConfig value={{ provider: () => new Map() }}>
                <MemoryRouter>
                    <UIContext.Provider value={uiContext}>
                        <ManagementUserView />
                    </UIContext.Provider>
                </MemoryRouter>
            </SWRConfig>,
        );

        await eventUser.click(screen.getByRole('button', { name: 'Bearbeiten' }));
        const dialog = screen.getByRole('dialog', { name: 'Max Mustermann bearbeiten' });

        expect(await within(dialog).findByRole('checkbox', { name: 'Org delivery group' })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: 'Org delivery gallery' })).toBeInTheDocument();

        await waitFor(() => {
            expect(fetcher).toHaveBeenCalledWith('/api/management/orgs/org-1');
        });
        expect(fetcher).not.toHaveBeenCalledWith('/api/management/galleries');
    });
});
