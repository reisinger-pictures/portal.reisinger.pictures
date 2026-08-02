import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ProtectedDashboard from '../ProtectedDashboard';
import { useAuth } from '../../logic/useAuth';
import { usePermissions } from '../../logic/usePermissions';

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
    };
});

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../SearchView', () => ({
    default: () => <div data-testid="search-view" />,
}));

vi.mock('../management/ManagementDashboard', () => ({
    default: () => <div data-testid="management-dashboard" />,
}));

vi.mock('../client/ClientDashboard', () => ({
    default: () => <div data-testid="client-dashboard" />,
}));

const mockUser = {
    id: 'u1',
    name: 'Test User',
    email: 'test@example.com',
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    roles: [],
};

function renderProtectedDashboard() {
    return render(
        <MemoryRouter>
            <ProtectedDashboard />
        </MemoryRouter>,
    );
}

describe('ProtectedDashboard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        vi.mocked(usePermissions).mockReturnValue({
            isStaff: false,
            isAdmin: false,
            isSuperAdmin: false,
            isPhotographer: false,
            isOrgAdmin: false,
            showOrgsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            canAccessProjectsBoard: false,
            canAccessProductionBoard: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });
    });

    it('shows loading spinner while auth is loading', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: true,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        renderProtectedDashboard();
        expect(screen.getByTestId('app-loader')).toBeInTheDocument();
        expect(screen.queryByTestId('search-view')).not.toBeInTheDocument();
    });

    it('renders SearchView for unauthenticated user', () => {
        renderProtectedDashboard();
        expect(screen.getByTestId('search-view')).toBeInTheDocument();
        expect(screen.queryByTestId('management-dashboard')).not.toBeInTheDocument();
        expect(screen.queryByTestId('client-dashboard')).not.toBeInTheDocument();
    });

    it('renders ManagementDashboard for staff user', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: mockUser,
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
            isSuperAdmin: false,
            isPhotographer: true,
            isOrgAdmin: false,
            showOrgsSection: false,
            canEditMetadata: true,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            canAccessProjectsBoard: false,
            canAccessProductionBoard: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        renderProtectedDashboard();
        expect(screen.getByTestId('management-dashboard')).toBeInTheDocument();
        expect(screen.queryByTestId('client-dashboard')).not.toBeInTheDocument();
    });

    it('renders ClientDashboard for non-staff authenticated user', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: mockUser,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue({
            isStaff: false,
            isAdmin: false,
            isSuperAdmin: false,
            isPhotographer: false,
            isOrgAdmin: false,
            showOrgsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            canAccessProjectsBoard: false,
            canAccessProductionBoard: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        renderProtectedDashboard();
        expect(screen.getByTestId('client-dashboard')).toBeInTheDocument();
        expect(screen.queryByTestId('management-dashboard')).not.toBeInTheDocument();
    });
});
