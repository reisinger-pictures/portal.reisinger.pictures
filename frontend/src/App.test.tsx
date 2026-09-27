import {render, screen} from '@testing-library/react';
import {beforeEach, describe, expect, it, vi} from 'vitest';
import {MemoryRouter, Route, Routes, useLocation} from 'react-router-dom';
import {ProtectedRoute} from './App';
import App from './App';
import {useAuth} from './logic/useAuth';
import {usePermissions} from './logic/usePermissions';

vi.mock('./logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('./logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

// Throwing a never-resolving promise suspends the boundary indefinitely, which
// is the production state while the lazy ProtectedDashboard chunk is being
// fetched. Only the `Suspense fallback boot signal` block below renders <App/>;
// the ProtectedRoute cases render the route element directly and never reach
// this module.
vi.mock('./ui/ProtectedDashboard', () => ({
    default: () => {
        throw new Promise<never>(() => {});
    },
}));

function CurrentLocation() {
    const location = useLocation();
    return <output aria-label="Aktuelle Route">{location.pathname}{location.search}{location.hash}</output>;
}

const routeCases: Array<{
    path: '/galleries' | '/admin-orders';
    requiredFeature?: 'b2b';
    heading: string;
    query: string;
}> = [
    {path: '/galleries', heading: 'Galleries management shell', query: '?tab=structure'},
    {path: '/admin-orders', requiredFeature: 'b2b', heading: 'Orders management shell', query: '?status=open'},
];

describe('ProtectedRoute trailing-slash normalization', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useAuth).mockReturnValue({
            user: {
                id: 'user-1',
                guest_id: null,
                name: 'Admin',
                email: 'admin@example.com',
                billing_name: null,
                billing_company: null,
                billing_street: null,
                billing_zip: null,
                billing_city: null,
                brand: null,
                is_cross_brand: false,
                is_super_admin: true,
                is_admin: true,
                is_photographer: true,
                is_org_admin: false,
                is_power_user: false,
                is_pending: false,
                can_edit_metadata: true,
                can_purchase_upgrades: false,
                roles: ['admin'],
                transient_galleries: [],
                transient_meta_galleries: [],
                photographer_gallery_groups: [],
            },
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue({
            isStaff: true,
            isAdmin: true,
            isSuperAdmin: true,
            isPhotographer: true,
            isOrgAdmin: false,
            showOrgsSection: true,
            canEditMetadata: true,
            isPowerUser: true,
            canAccessB2BFeatures: true,
            canAccessProjectsBoard: true,
            canAccessProductionBoard: true,
            showCRM: true,
            showInvoicing: true,
            showPayouts: true,
        });
    });

    it.each(routeCases)('redirects $path/ before rendering its management shell', ({path, requiredFeature, heading, query}) => {
        render(
            <MemoryRouter initialEntries={[`${path}/${query}#content`]}>
                <Routes>
                    <Route
                        path={path}
                        element={(
                            <ProtectedRoute requiredFeature={requiredFeature}>
                                <section aria-labelledby="shell-heading">
                                    <h1 id="shell-heading">{heading}</h1>
                                </section>
                            </ProtectedRoute>
                        )}
                    />
                </Routes>
                <CurrentLocation/>
            </MemoryRouter>
        );

        expect(screen.getByRole('heading', {name: heading})).toBeInTheDocument();
        expect(screen.getByRole('status', {name: 'Aktuelle Route'})).toHaveTextContent(`${path}${query}#content`);
    });

    it('redirects a failed auth check to the root authentication surface, not a missing /login route', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: false,
            isError: new Error('Unauthenticated'),
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        render(
            <MemoryRouter initialEntries={['/profile']}>
                <Routes>
                    <Route path="/" element={<main><h1>Authentifizierung</h1></main>}/>
                    <Route
                        path="/profile"
                        element={(
                            <ProtectedRoute>
                                <section><h1>Privates Profil</h1></section>
                            </ProtectedRoute>
                        )}
                    />
                </Routes>
                <CurrentLocation/>
            </MemoryRouter>
        );

        expect(screen.getByRole('heading', {name: 'Authentifizierung'})).toBeInTheDocument();
        expect(screen.queryByRole('heading', {name: 'Privates Profil'})).not.toBeInTheDocument();
        expect(screen.getByRole('status', {name: 'Aktuelle Route'})).toHaveTextContent('/');
    });
});

/**
 * The Suspense fallback is one half of a contract whose other half lives in the
 * Playwright helper: `AuthHelper.login()` can only wait for "the boot is
 * finished" by asserting the ABSENCE of both boot loaders, because the
 * ProtectedDashboard chunk is the window in which `app-loader` does not exist at
 * all. So the two test ids have to be a set that is actually reachable:
 *
 *   - `app-loader-fallback` is on screen for the whole chunk fetch. If it were
 *     renamed or dropped, the helper's first wait would silently go vacuous
 *     again — an assertion that can never fail, which is the defect itself.
 *   - `app-loader` is absent for that same window, which is exactly why it
 *     cannot carry the wait on its own. Asserted here so that reasoning stays
 *     pinned to the rendered markup instead of a comment that can rot.
 */
describe('Suspense fallback boot signal', () => {
    it('renders app-loader-fallback while the route chunk is in flight, and app-loader is absent', () => {
        render(
            <MemoryRouter>
                <App/>
            </MemoryRouter>
        );

        expect(screen.getByTestId('app-loader-fallback')).toBeInTheDocument();
        expect(screen.queryByTestId('app-loader')).not.toBeInTheDocument();
    });
});
