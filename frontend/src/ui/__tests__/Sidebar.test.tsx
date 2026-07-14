import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../logic/useAuth';
import { usePermissions } from '../../logic/usePermissions';
import { useCart } from '../../logic/CartContext';
import { useBrand } from '../../logic/useBrand';
import { useLicensingMode } from '../../logic/useLicensingMode';

// --------------------------------------------------------------------------
// Mocks
// --------------------------------------------------------------------------

// Keep real router components (Link, MemoryRouter), mock only useNavigate
vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
    };
});

vi.mock('../../logic/useBrand', () => ({
    useBrand: vi.fn(() => ({
        brand: 'rp',
        features: { coupons: true, orgs: true },
        config: null,
        logoSrc: '/brands/rp/android-chrome-192x192.png',
        svgUrl: '/brands/rp/safari-pinned-tab.svg',
        portalName: 'Reisinger Foto Portal',
        impressumUrl: 'https://reisinger.pictures/impressum/',
        theme: { light: 'rp-light', dark: 'rp-dark' },
        primaryColor: '#1E5631',
        secondaryColor: '#A4B494',
    })),
}));

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: vi.fn(() => 'scope_licensing'),
}));

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../logic/CartContext', () => ({
    useCart: vi.fn(),
}));

vi.mock('../components/SidebarLoginForm', () => ({
    default: () => <div data-testid="sidebar-login-form" />,
}));

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

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

const defaultPermissions = {
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: false,
    showCRM: false,
    showInvoicing: false,
    showPayouts: false,
};

function renderSidebar(props: Record<string, unknown> = {}) {
    return renderWithProviders(
        <MemoryRouter>
            <Sidebar {...props} />
        </MemoryRouter>,
    );
}

// --------------------------------------------------------------------------
// Suite
// --------------------------------------------------------------------------

describe('Sidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        // Default: guest user, no permissions, empty cart
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
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });
        vi.mocked(useCart).mockReturnValue({
            items: [],
            addToCart: vi.fn(),
            removeFromCart: vi.fn(),
            clearCart: vi.fn(),
            totalAmount: 0,
            itemCount: 0,
        });
    });

    // ----------------------------------------------------------------------
    // Branding
    // ----------------------------------------------------------------------

    it('renders the logo and portal name', () => {
        renderSidebar();
        expect(screen.getByAltText('Logo')).toHaveAttribute(
            'src',
            '/brands/rp/android-chrome-192x192.png',
        );
        expect(screen.getByText('Reisinger Foto Portal')).toBeInTheDocument();
    });

    // ----------------------------------------------------------------------
    // Guest / Login
    // ----------------------------------------------------------------------

    it('shows the login form when no user is authenticated (guest state)', () => {
        renderSidebar();
        expect(screen.getByTestId('sidebar-login-form')).toBeInTheDocument();

        // Guest must not see authenticated links
        expect(screen.queryByText('Mein Profil')).not.toBeInTheDocument();
        expect(screen.queryByText('Einkäufe & Anfragen')).not.toBeInTheDocument();
        expect(screen.queryByText('Abmelden')).not.toBeInTheDocument();
    });

    // ----------------------------------------------------------------------
    // Cart badge
    // ----------------------------------------------------------------------

    it('shows a cart badge when itemCount is greater than 0', () => {
        vi.mocked(useCart).mockReturnValue({
            items: [],
            addToCart: vi.fn(),
            removeFromCart: vi.fn(),
            clearCart: vi.fn(),
            totalAmount: 0,
            itemCount: 3,
        });
        renderSidebar();

        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('3').closest('.badge')).toBeInTheDocument();
    });

    it('does not render a cart badge when itemCount is 0', () => {
        renderSidebar();
        expect(screen.queryByText('0')).not.toBeInTheDocument();
        // The cart link itself is always rendered
        expect(screen.getByText('Warenkorb')).toBeInTheDocument();
    });

    // ----------------------------------------------------------------------
    // Authenticated user — non-staff
    // ----------------------------------------------------------------------

    describe('authenticated user', () => {
        beforeEach(() => {
            vi.mocked(useAuth).mockReturnValue({
                user: mockUser,
                isLoading: false,
                isError: undefined,
                login: vi.fn(),
                register: vi.fn(),
                logout: vi.fn(),
                mutate: vi.fn(),
            });
        });

        it('shows account links for a logged-in non-staff user', () => {
            renderSidebar();

            // Common user links
            expect(screen.getByText('Suche & Entdecken')).toBeInTheDocument();
            expect(screen.getByText('Mein Profil')).toBeInTheDocument();
            expect(screen.getByText('Einkäufe & Anfragen')).toBeInTheDocument();

            // Logout button
            expect(screen.getByText('Abmelden')).toBeInTheDocument();

            // Staff sections must NOT appear
            expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
            expect(screen.queryByText('Galerien & Ordner')).not.toBeInTheDocument();
            expect(screen.queryByText('Auswertungen')).not.toBeInTheDocument();
        });

        // ------------------------------------------------------------------
        // Staff navigation
        // ------------------------------------------------------------------

        it('renders photographer navigation', () => {
            vi.mocked(usePermissions).mockReturnValue({
                ...defaultPermissions,
                isStaff: true,
                isAdmin: false,
                isSuperAdmin: false,
                isPhotographer: true,
                isOrgAdmin: false,
                showOrgsSection: false,
            });
            renderSidebar();

            expect(screen.getByText('Dashboard')).toBeInTheDocument();
            expect(screen.getByText('Galerien & Ordner')).toBeInTheDocument();
            // "Suche & Entdecken" appears in both staff + user sections → 2 matches
            expect(screen.getAllByText('Suche & Entdecken')).toHaveLength(2);
            expect(screen.getByText('Auswertungen')).toBeInTheDocument();

            // Admin-only must be hidden
            expect(screen.queryByText('Shop-Bestellungen')).not.toBeInTheDocument();
            expect(screen.queryByText('Payouts & Abrechnung')).not.toBeInTheDocument();
            expect(screen.queryByText('Einstellungen')).not.toBeInTheDocument();
        });

        it('renders admin navigation', () => {
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
                showCRM: false,
                showInvoicing: false,
                showPayouts: false,
            });
            renderSidebar();

            expect(screen.getByText('Shop-Bestellungen')).toBeInTheDocument();
            expect(screen.getByText('Payouts & Abrechnung')).toBeInTheDocument();
            expect(screen.getByText('Einstellungen')).toBeInTheDocument();

            // Super-admin-only must be hidden
            expect(screen.queryByText('Kunden (CRM)')).not.toBeInTheDocument();
            expect(screen.queryByText('Produkte & Leistungen')).not.toBeInTheDocument();
            expect(screen.queryByText('Textbausteine')).not.toBeInTheDocument();
        });

        it('renders super-admin navigation', () => {
            vi.mocked(usePermissions).mockReturnValue({
                ...defaultPermissions,
                isStaff: true,
                isAdmin: true,
                isSuperAdmin: true,
                isPhotographer: false,
                isOrgAdmin: false,
                showOrgsSection: true,
            });
            renderSidebar();

            expect(screen.getByText('Kunden (CRM)')).toBeInTheDocument();
            expect(screen.getByText('Produkte & Leistungen')).toBeInTheDocument();
            expect(screen.getByText('Textbausteine')).toBeInTheDocument();
            expect(screen.getByText('Manuelles Angebot')).toBeInTheDocument();
            expect(screen.getByText('Manuelle Rechnung')).toBeInTheDocument();
        });

        it('renders org-admin navigation with correct labels', () => {
            vi.mocked(usePermissions).mockReturnValue({
                ...defaultPermissions,
                isStaff: true,
                isAdmin: false,
                isSuperAdmin: false,
                isPhotographer: false,
                isOrgAdmin: true,
                showOrgsSection: true,
            });
            renderSidebar();

            expect(screen.getByText('Organisationen')).toBeInTheDocument();
            // Org-admin sees "Mein Team" instead of "Benutzer & Rechte"
            expect(screen.getByText('Mein Team')).toBeInTheDocument();

            // Admin-only must still be hidden
            expect(screen.queryByText('Shop-Bestellungen')).not.toBeInTheDocument();
        });

        it('shows photographer payouts link for photographer users', () => {
            vi.mocked(usePermissions).mockReturnValue({
                ...defaultPermissions,
                isStaff: true,
                isAdmin: false,
                isSuperAdmin: false,
                isPhotographer: true,
                isOrgAdmin: false,
                showOrgsSection: false,
            });
            renderSidebar();

            expect(screen.getByText('Meine Abrechnungen')).toBeInTheDocument();
        });
    });
});

describe('Volume licensing admin navigation', () => {
    beforeEach(() => {
        vi.mocked(useLicensingMode).mockReturnValue('volume_licensing');
        vi.mocked(useAuth).mockReturnValue({
            user: { ...mockUser, is_admin: true },
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isStaff: true,
            isAdmin: true,
            isSuperAdmin: false,
            isPhotographer: true,
            isOrgAdmin: false,
            showOrgsSection: false,
            canEditMetadata: true,
        });
        vi.mocked(useBrand).mockReturnValue({
            brand: 'rp',
            features: { coupons: true, orgs: true },
            config: null,
            logoSrc: '/brands/rp/android-chrome-192x192.png',
            svgUrl: '/brands/rp/safari-pinned-tab.svg',
            portalName: 'Reisinger Foto Portal',
            impressumUrl: 'https://reisinger.pictures/impressum/',
            theme: { light: 'rp-light', dark: 'rp-dark' },
            primaryColor: '#1E5631',
            secondaryColor: '#A4B494',
        });
    });

    it('shows "Gutscheincode" link when isStaff, isAdmin, and volume licensing is active', () => {
        renderSidebar();

        expect(screen.getByText('Gutscheincode')).toBeInTheDocument();
        expect(screen.getByText('Marketing')).toBeInTheDocument();
    });

    it('hides "Gutscheincode" link when isAdmin is false', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isStaff: true,
            isSuperAdmin: false,
            isAdmin: false,
            isPhotographer: true,
            isOrgAdmin: false,
            showOrgsSection: false,
        });
        renderSidebar();

        expect(screen.queryByText('Gutscheincode')).not.toBeInTheDocument();
    });

    it('hides "Gutscheincode" link when licensing is scope_licensing', () => {
        vi.mocked(useLicensingMode).mockReturnValue('scope_licensing');
        renderSidebar();

        expect(screen.queryByText('Gutscheincode')).not.toBeInTheDocument();
    });
});
