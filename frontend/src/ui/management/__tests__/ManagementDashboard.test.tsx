import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import type { AuthMeUser } from '../../../api';
import { useAuth } from '../../../logic/useAuth';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import { usePermissions } from '../../../logic/usePermissions';
import { useSearch } from '../../../logic/useSearch';
import { useDashboard } from '../../components/DashboardContext';
import ManagementDashboard from '../ManagementDashboard';
import { renderWithProviders } from '../../../test-setup';

vi.mock('../../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../../logic/useBrand', () => ({
    useBrand: vi.fn(),
}));

vi.mock('../../../logic/useLicenseTerms', () => ({
    useBillingDetails: vi.fn(),
}));

vi.mock('../../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../../logic/useSearch', () => ({
    useSearch: vi.fn(),
}));

vi.mock('../../components/DashboardContext', () => ({
    useDashboard: vi.fn(),
}));

vi.mock('../../components/DashboardLayout', () => ({
    default: ({
        children,
        header,
    }: {
        children: ReactNode;
        header?: (props: {
            onMenuClick: () => void;
            isSidebarOpen: boolean;
            sidebarId: string;
        }) => ReactNode;
    }) => (
        <main>
            {header?.({ onMenuClick: vi.fn(), isSidebarOpen: false, sidebarId: 'dashboard-sidebar' })}
            {children}
        </main>
    ),
}));

vi.mock('../ManagementStructureView', () => ({
    default: () => <div data-testid="management-structure" />,
}));

vi.mock('../ManagementFtpInbox', () => ({
    default: () => null,
}));

vi.mock('../../components/SearchBarWithSuggestions', () => ({
    default: () => null,
}));

vi.mock('../components/PhotographerTeamModal', () => ({
    default: () => null,
}));

const baseUser: AuthMeUser = {
    id: 'user-1',
    guest_id: null,
    name: 'Test Photographer',
    email: 'photographer@example.com',
    billing_name: null,
    billing_company: null,
    billing_street: null,
    billing_zip: null,
    billing_city: null,
    brand: 'rp',
    is_cross_brand: false,
    is_super_admin: false,
    is_admin: false,
    is_photographer: true,
    is_org_admin: false,
    is_power_user: false,
    is_pending: false,
    can_edit_metadata: true,
    can_purchase_upgrades: false,
    roles: ['photographer'],
    ai_is_unconfigured: false,
    transient_galleries: [],
    transient_meta_galleries: [],
    photographer_gallery_groups: [],
};

function authState(aiIsUnconfigured: boolean) {
    return {
        user: { ...baseUser, ai_is_unconfigured: aiIsUnconfigured },
        isLoading: false,
        isError: undefined,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
        mutate: vi.fn(),
    };
}

function renderDashboard() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/']}>
            <ManagementDashboard />
        </MemoryRouter>,
    );
}

describe('ManagementDashboard AI configuration notice', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useAuth).mockReturnValue(authState(false));
        vi.mocked(useBrand).mockReturnValue({
            brand: 'rp',
            config: null,
            logoSrc: '/brands/rp/android-chrome-192x192.png',
            svgUrl: '/brands/rp/safari-pinned-tab.svg',
            portalName: 'Reisinger Foto Portal',
            impressumUrl: null,
            features: { coupons: true, orgs: true },
            theme: { light: 'rp-light', dark: 'rp-dark' },
            primaryColor: '#1E5631',
            secondaryColor: '#A4B494',
        });
        vi.mocked(useBillingDetails).mockReturnValue({
            billingDetails: {
                bank_holder: 'Reisinger Pictures GmbH',
                bank_iban: 'AT123456789',
                bank_bic: 'TESTBICXXX',
                company_street: 'Teststraße 1',
                company_zip: '1010',
                company_city: 'Wien',
                company_country: 'Österreich',
                company_email: 'office@example.com',
            },
            isLoading: false,
        });
        vi.mocked(usePermissions).mockReturnValue({
            isStaff: true,
            isSuperAdmin: false,
            isAdmin: false,
            isPhotographer: true,
            isOrgAdmin: false,
            canEditMetadata: true,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            canAccessProjectsBoard: false,
            canAccessProductionBoard: true,
            showOrgsSection: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });
        vi.mocked(useSearch).mockReturnValue({
            results: { galleries: [], photos: [] },
            isLoading: false,
            isError: undefined,
        });
        vi.mocked(useDashboard).mockReturnValue({
            tree: { groups: [], root_galleries: [] },
            isLoading: false,
            isError: undefined,
            mutate: vi.fn(),
            onOpenGalleryModal: vi.fn(),
            onOpenGroupModal: vi.fn(),
            onEditGroup: vi.fn(),
            onEditGallery: vi.fn(),
        });
    });

    it('shows the warning when the auth contract marks AI as unconfigured', () => {
        vi.mocked(useAuth).mockReturnValue(authState(true));

        renderDashboard();

        const main = within(screen.getByRole('main'));
        expect(
            main.getByRole('heading', {
                name: 'KI-Bildbeschreibung nicht konfiguriert',
            }),
        ).toBeVisible();
    });

    it('does not show the warning when AI is configured', () => {
        renderDashboard();

        const main = within(screen.getByRole('main'));
        expect(
            main.queryByRole('heading', {
                name: 'KI-Bildbeschreibung nicht konfiguriert',
            }),
        ).not.toBeInTheDocument();
    });
});
