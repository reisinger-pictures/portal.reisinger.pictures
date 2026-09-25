import {render, screen} from '@testing-library/react';
import {beforeEach, describe, expect, it, vi} from 'vitest';
import {MemoryRouter, Route, Routes, useLocation} from 'react-router-dom';
import {ProtectedRoute} from './App';
import {useAuth} from './logic/useAuth';
import {usePermissions} from './logic/usePermissions';

vi.mock('./logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('./logic/usePermissions', () => ({
    usePermissions: vi.fn(),
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
                name: 'Admin',
                email: 'admin@example.com',
                is_super_admin: true,
                is_admin: true,
                is_photographer: true,
                is_pending: false,
                can_edit_metadata: true,
                roles: ['admin'],
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
