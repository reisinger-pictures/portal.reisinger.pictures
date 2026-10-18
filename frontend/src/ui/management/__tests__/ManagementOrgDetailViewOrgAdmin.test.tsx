import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SWRConfig } from 'swr';
import type { ReactNode } from 'react';
import { renderWithProviders } from '../../../test-setup';
import { UIContext, type UIContextType } from '../../components/UIContext';
import { fetcher } from '../../../api';
import { useUsers, type UserDetailed } from '../../../logic/useUsers';
import { usePermissions, type Permissions } from '../../../logic/usePermissions';
import ManagementOrgDetailView from '../ManagementOrgDetailView';

// The two SWR consumers of `/api/management/orgs/{id}` must not share a cache
// entry: `useOrgs(id)` reads the raw `Org`, `useProtectedGalleries()` projects
// `gallery_assignment_options` into a gallery tree. They request the same
// endpoint but their shapes are disjoint — the raw object has no `groups`, the
// projection has no `name`/`domain`/`users`.
//
// This test therefore renders the real view with the real `useOrgs` and the
// real `useProtectedGalleries` against one shared SWR provider. Mocking either
// hook would hide exactly the defect: a serialized-key collision makes one hook
// read the other's payload (`flattenGroups(undefined)` or an org with no name).
// Only the unrelated `useUsers`/`usePermissions` and the `fetcher` boundary are
// mocked, so the collision is exercised through the real hook interplay.

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

vi.mock('../../components/PageLayout', () => ({
    default: ({ children }: { children: ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
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
    // Delivery group `g4` is genuinely assignable and therefore part of the
    // projection, but it is not (yet) attached to the org. It only shows up if
    // the hook actually read `gallery_assignment_options.groups`.
    orgScope: {
        org_id: 'org-1',
        gallery_ids: [],
        gallery_group_ids: ['g1', 'g4'],
    },
};

// One endpoint, one payload: the server returns the raw organisation and, for an
// org admin, additionally the filtered assignment projection. Each hook reads
// only its own slice — which is precisely what the distinct cache key protects.
const orgDetail = {
    id: 'org-1',
    name: 'Firma XYZ',
    domain: 'firma.de',
    invoice_frequency: 'monthly' as const,
    default_flatrate_level: 'none' as const,
    shared_flatrate_cents: 0,
    auto_join_policy: 'requires_invite' as const,
    users_count: 1,
    gallery_groups_count: 1,
    open_delivery_notes_count: 0,
    users: [{ id: 'u1', name: 'Max Mustermann', email: 'max@example.com' }],
    gallery_groups: [{ id: 'g1', name: 'Print', parent_id: null }],
    gallery_assignment_options: {
        groups: [
            {
                id: 'g1',
                name: 'Print',
                parent_id: null,
                children: [{ id: 'g2', name: 'Hochzeit', parent_id: 'g1', children: [], galleries: [] }],
                galleries: [],
            },
            { id: 'g4', name: 'Delivery group', parent_id: null, children: [], galleries: [] },
        ],
        root_galleries: [],
    },
};

function renderView() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map(), dedupingInterval: 0 }}>
            <MemoryRouter initialEntries={['/orgs/org-1']}>
                <UIContext.Provider value={uiContext}>
                    <Routes>
                        <Route path="/orgs/:id" element={<ManagementOrgDetailView />} />
                    </Routes>
                </UIContext.Provider>
            </MemoryRouter>
        </SWRConfig>,
    );
}

describe('ManagementOrgDetailView org-admin hook interplay', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useUsers).mockReturnValue({
            users: [] as UserDetailed[],
            roles: [],
            createUser: vi.fn().mockResolvedValue(undefined),
            updateUser: vi.fn().mockResolvedValue(undefined),
            deleteUser: vi.fn().mockResolvedValue(undefined),
        });
        vi.mocked(usePermissions).mockReturnValue(permissions);
        vi.mocked(fetcher).mockImplementation(async <T,>(url: string): Promise<T> => {
            if (url === '/api/management/orgs/org-1') {
                return orgDetail as T;
            }

            throw new Error(`Unexpected request: ${url}`);
        });
    });

    it('renders the raw organisation and the projected options without sharing a cache entry', async () => {
        renderView();

        // The view only leaves the loading state once the raw org resolved.
        const main = await screen.findByTestId('page-layout');

        // Raw `Org` slice: the settings form is seeded from the fetched org.
        expect(within(main).getByDisplayValue('Firma XYZ')).toBeInTheDocument();
        expect(within(main).getByDisplayValue('firma.de')).toBeInTheDocument();

        // Projected slice: `g4` is only present in `gallery_assignment_options`,
        // so it proves the gallery hook transformed its own payload.
        expect(within(main).getByRole('checkbox', { name: 'Print' })).toBeChecked();
        expect(within(main).getByRole('checkbox', { name: 'Delivery group' })).not.toBeChecked();

        // `orgScope` narrows the offered groups: the child `g2` is in the tree
        // but outside the delegation scope, so it must not be offered.
        expect(within(main).queryByRole('checkbox', { name: /Hochzeit/ })).not.toBeInTheDocument();

        expect(fetcher).toHaveBeenCalledWith('/api/management/orgs/org-1');
    });
});
