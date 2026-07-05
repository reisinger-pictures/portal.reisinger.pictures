import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import ManagementGalleryView from '../management/ManagementGalleryView';

vi.mock('../../logic/useGallery', () => ({
    useGallery: vi.fn(),
}));

vi.mock('../../logic/useGalleries', () => ({
    useProtectedGalleries: vi.fn(() => ({
        tree: { groups: [], root_galleries: [] },
        updateGallery: vi.fn(),
        deleteGallery: vi.fn(),
    })),
    flattenGroups: vi.fn(() => []),
}));

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../logic/useBrand', () => ({
    useBrand: vi.fn(() => ({ isSrp: false, logoSrc: '', portalName: 'Test', impressumUrl: '' })),
}));

vi.mock('../../logic/usePhotoSwipe', () => ({
    usePhotoSwipe: vi.fn(),
}));

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useParams: () => ({ '*': 'test-gallery' }),
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => (
        <div data-testid="error-message">{message}</div>
    ),
}));

vi.mock('../components/GalleryHeader', () => ({
    default: () => <div data-testid="gallery-header" />,
}));

vi.mock('../management/components/GalleryAccessModal', () => ({
    default: () => <div data-testid="gallery-access-modal" />,
}));

vi.mock('../management/components/EmailComposerModal', () => ({
    default: () => <div data-testid="email-composer-modal" />,
}));

vi.mock('../management/components/InviteModal', () => ({
    default: () => <div data-testid="invite-modal" />,
}));

vi.mock('../management/components/UploadDropzone', () => ({
    default: () => <div data-testid="upload-dropzone" />,
}));

vi.mock('../management/components/RatingStatusModal', () => ({
    default: () => <div data-testid="rating-status-modal" />,
}));

vi.mock('../management/components/GalleryMetadataDefaultsModal', () => ({
    default: () => <div data-testid="metadata-defaults-modal" />,
}));

vi.mock('../components/GalleryModals', () => ({
    default: () => <div data-testid="gallery-modals" />,
}));

vi.mock('../management/components/ManagementGalleryActions', () => ({
    default: () => <div data-testid="management-gallery-actions" />,
}));

vi.mock('../management/components/PhotographerTeamModal', () => ({
    default: () => <div data-testid="photographer-team-modal" />,
}));

vi.mock('../management/components/AIBatchEditModal', () => ({
    default: () => <div data-testid="ai-batch-edit-modal" />,
}));

vi.mock('../management/components/GalleryCouponsTab', () => ({
    default: () => <div data-testid="gallery-coupons-tab" />,
}));

import { useGallery } from '../../logic/useGallery';
import { useAuth } from '../../logic/useAuth';
import { usePermissions } from '../../logic/usePermissions';

const mockGallery = {
    id: 'g1',
    name: 'Test Gallery',
    slug: 'test-gallery',
    full_path: 'test-gallery',
    type: 'delivery' as const,
    is_live: false,
    is_public: true,
};

const baseGalleryData = {
    gallery: mockGallery,
    photos: [],
    isLoading: false,
    totalPhotos: 0,
    size: 1,
    setSize: vi.fn(),
    isReachingEnd: true,
    wantsNotifications: false,
    toggleOptIn: vi.fn(),
    canManage: true,
    breadcrumbs: [],
    downloadsCount: 0,
    notified_count: 0,
    ratePhoto: vi.fn(),
    mutate: vi.fn(),
    isError: undefined,
};

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/admin/galleries/test-gallery']}>
            <ManagementGalleryView />
        </MemoryRouter>,
    );
}

describe('ManagementGalleryView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useAuth).mockReturnValue({
            user: { id: 'u1', name: 'Test', email: 'test@test.com', is_super_admin: false, is_admin: true, is_photographer: true, is_pending: false, can_edit_metadata: true, roles: [], ai_is_unconfigured: false },
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
            showTenantsSection: false,
            canEditMetadata: true,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        vi.mocked(useGallery).mockReturnValue(baseGalleryData);
    });

    it('shows loading spinner while gallery data is loading', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isLoading: true,
            photos: [],
            gallery: undefined,
        });

        renderView();
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('shows error message when gallery is not found', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isError: new Error('Not found'),
            gallery: undefined,
            photos: [],
        });

        renderView();
        expect(screen.getByTestId('error-message')).toBeInTheDocument();
        expect(screen.getByText('Galerie nicht gefunden.')).toBeInTheDocument();
    });

    it('renders gallery with photos', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            photos: [
                { id: 'p1', gallery_id: 'g1', filename: 'photo1.jpg', lr_uuid: 'uuid-1', url: '/p1.jpg', thumb_url: '/p1-thumb.jpg', title: 'Photo 1', width: 800, height: 600, rating: 0, comment: '' },
            ],
            totalPhotos: 1,
        });

        renderView();
        expect(screen.getByTestId('page-layout')).toBeInTheDocument();
        expect(screen.getByTestId('gallery-header')).toBeInTheDocument();
        expect(screen.getByTestId('management-gallery-actions')).toBeInTheDocument();
        expect(screen.getByTestId('upload-dropzone')).toBeInTheDocument();
    });

    it('shows empty state when no photos exist', () => {
        renderView();
        expect(screen.getByText('Noch keine Bilder vorhanden')).toBeInTheDocument();
    });

    it('shows Mehr laden button when not reaching end', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isReachingEnd: false,
            photos: [
                { id: 'p1', gallery_id: 'g1', filename: 'photo1.jpg', lr_uuid: 'uuid-1', url: '/p1.jpg', thumb_url: '/p1-thumb.jpg', title: 'Photo 1', width: 800, height: 600, rating: 0, comment: '' },
            ],
            totalPhotos: 10,
        });

        renderView();
        expect(screen.getByText('Mehr laden')).toBeInTheDocument();
    });
});
