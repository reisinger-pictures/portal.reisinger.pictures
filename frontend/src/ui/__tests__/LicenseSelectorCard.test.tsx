import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import LicenseSelectorCard from '../client/components/LicenseSelectorCard';

vi.mock('../../logic/useLicenseCatalog', () => ({
    useLicenseCatalog: vi.fn(),
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

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

import { useLicenseCatalog } from '../../logic/useLicenseCatalog';
import { useAuth } from '../../logic/useAuth';
import { usePermissions } from '../../logic/usePermissions';
import { useCart } from '../../logic/CartContext';
import { useUI } from '../components/UIContext';

const mockPhoto = {
    id: 'p1',
    gallery_id: 'g1',
    filename: 'photo1.jpg',
    lr_uuid: 'uuid-1',
    url: '/p1.jpg',
    thumb_url: '/p1-thumb.jpg',
    title: 'Photo 1',
    width: 800,
    height: 600,
    rating: 0,
    comment: '',
    gallery: { id: 'g1', name: 'Gallery', slug: 'g1', full_path: 'g1', type: 'delivery' as const, is_live: false, is_public: true, effective_is_free_download: false },
};

const mockCatalog = {
    use_cases: [
        { id: 'uc1', name: 'Web-Nutzung', description: 'Für Web & Social Media', base_price: 5000, flatrate_tier: 'web', sort_order: 0, is_commercial: false },
        { id: 'uc2', name: 'Print', description: 'Für Printmedien', base_price: 15000, flatrate_tier: 'print', sort_order: 1, is_commercial: false },
    ],
    modifiers: [
        { id: 'm1', name: 'Titelseite', description: 'Nutzung auf Titelseite', percent_surcharge: 100, is_included_in_flatrate: false, sort_order: 0 },
    ],
};

const defaultAuth = {
    user: { id: 'u1', name: 'Test', email: 'test@test.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: ['power_user'], flatrate_level: 'web' as const },
    isLoading: false,
    isError: undefined,
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    mutate: vi.fn(),
};

function renderCard(photo = mockPhoto) {
    return render(
        <MemoryRouter>
            <LicenseSelectorCard photo={photo} />
        </MemoryRouter>,
    );
}

describe('LicenseSelectorCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: mockCatalog,
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        vi.mocked(useAuth).mockReturnValue(defaultAuth);

        vi.mocked(usePermissions).mockReturnValue({
            isStaff: false,
            isAdmin: false,
            isSuperAdmin: false,
            isPhotographer: false,
            isOrgAdmin: false,
            showTenantsSection: false,
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

        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('shows loading spinner while catalog is loading', () => {
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: undefined,
            isLoading: true,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        renderCard();
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('renders use cases with correct names and prices', () => {
        renderCard();
        expect(screen.getByText('Lizenz wählen')).toBeInTheDocument();
        expect(screen.getByText('Web-Nutzung')).toBeInTheDocument();
        expect(screen.getByText('Print')).toBeInTheDocument();
    });

    it('selects a use case by clicking its radio button', async () => {
        const user = userEvent.setup();
        renderCard();

        const radioButtons = screen.getAllByRole('radio');
        await user.click(radioButtons[1]);
        expect(radioButtons[1]).toBeChecked();
    });

    it('shows modifiers section when catalog has modifiers', () => {
        renderCard();
        expect(screen.getByText('Titelseite')).toBeInTheDocument();
        expect(screen.getByText(/100%/)).toBeInTheDocument();
    });

    it('adds item to cart when In den Warenkorb is clicked', async () => {
        const user = userEvent.setup();
        const addToCart = vi.fn();
        const showToast = vi.fn();

        vi.mocked(useAuth).mockReturnValue({
            ...defaultAuth,
            user: { id: 'u1', name: 'Test', email: 'test@test.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: ['power_user'], flatrate_level: 'none' as const },
        });

        vi.mocked(useCart).mockReturnValue({
            items: [],
            addToCart,
            removeFromCart: vi.fn(),
            clearCart: vi.fn(),
            totalAmount: 0,
            itemCount: 0,
        });

        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });

        renderCard();
        await user.click(screen.getByText('In den Warenkorb'));

        expect(addToCart).toHaveBeenCalledWith(
            expect.objectContaining({
                photoId: 'p1',
                useCaseId: 'uc1',
            }),
        );
        expect(showToast).toHaveBeenCalledWith('success', 'In den Warenkorb gelegt');
    });

    it('shows Admin Download button for staff users', () => {
        vi.mocked(usePermissions).mockReturnValue({
            isStaff: true,
            isAdmin: true,
            isSuperAdmin: false,
            isPhotographer: true,
            isOrgAdmin: false,
            showTenantsSection: false,
            canEditMetadata: true,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        renderCard();
        expect(screen.getByText('Admin Download')).toBeInTheDocument();
    });

    it('shows Sonderanfrage section for users who can buy', () => {
        renderCard();
        expect(screen.getByText('Sonderanfrage (Angebot)')).toBeInTheDocument();
        expect(screen.getByText('Als Angebot anfragen')).toBeInTheDocument();
    });

    it('hides Sonderanfrage section for blocked clients', () => {
        vi.mocked(useAuth).mockReturnValue({
            ...defaultAuth,
            user: { id: 'u1', name: 'Test', email: 'test@test.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: ['client'], flatrate_level: 'web' as const },
        });

        renderCard();
        expect(screen.queryByText('Sonderanfrage (Angebot)')).not.toBeInTheDocument();
        expect(screen.getByText('Download')).toBeInTheDocument();
    });

    it('hides commercial use cases for editorial-only photos', () => {
        vi.mocked(useLicenseCatalog).mockReturnValue({
            catalog: {
                use_cases: [
                    { id: 'uc1', name: 'Web-Nutzung', description: 'Für Web & Social Media', base_price: 5000, flatrate_tier: 'web', sort_order: 0, is_commercial: false },
                    { id: 'uc2', name: 'Werbung', description: 'Kommerzielle Werbekampagne', base_price: 30000, flatrate_tier: 'print', sort_order: 1, is_commercial: true },
                ],
                modifiers: [],
            },
            isLoading: false,
            createUseCase: vi.fn(),
            updateUseCase: vi.fn(),
            deleteUseCase: vi.fn(),
            createModifier: vi.fn(),
            updateModifier: vi.fn(),
            deleteModifier: vi.fn(),
        });

        const editorialPhoto = {
            ...mockPhoto,
            effective_is_editorial_only: true,
        };

        renderCard(editorialPhoto);
        expect(screen.getByText('Web-Nutzung')).toBeInTheDocument();
        expect(screen.queryByText('Werbung')).not.toBeInTheDocument();
    });
});
