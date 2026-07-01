import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DeliveryView from '../client/DeliveryView';
import { useAuth } from '../../logic/useAuth';
import { usePermissions } from '../../logic/usePermissions';

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../logic/usePhotoSwipe', () => ({
    usePhotoSwipe: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

const mockPhotos = [
    {
        id: 'p1',
        gallery_id: 'g1',
        filename: 'photo1.jpg',
        lr_uuid: 'uuid-1',
        width: 2000,
        height: 1333,
        url: '/photos/photo1.jpg',
        thumb_url: '/thumbs/photo1.jpg',
        srcset: '/thumbs/photo1-400.jpg 400w',
        title: 'Photo 1',
        description: 'A test photo',
        artist: 'Test Artist',
        rating: 0,
        comment: '',
    },
    {
        id: 'p2',
        gallery_id: 'g1',
        filename: 'photo2.jpg',
        lr_uuid: 'uuid-2',
        width: 1920,
        height: 1080,
        url: '/photos/photo2.jpg',
        thumb_url: '/thumbs/photo2.jpg',
        srcset: '/thumbs/photo2-400.jpg 400w',
        title: 'Photo 2',
        description: '',
        artist: '',
        rating: 0,
        comment: '',
    },
];

const baseGallery = {
    id: 'g1',
    name: 'Test Gallery',
    slug: 'test-gallery',
    full_path: 'test-gallery',
    type: 'delivery' as const,
    is_live: false,
    is_public: true,
    effective_is_free_download: false,
};

const baseGalleryData = {
    gallery: baseGallery,
    photos: mockPhotos,
    isLoading: false,
    totalPhotos: 2,
    size: 1,
    setSize: vi.fn(),
    isReachingEnd: true,
    wantsNotifications: false,
    toggleOptIn: vi.fn(),
    canManage: false,
    breadcrumbs: [],
    downloadsCount: 0,
    notified_count: 0,
    ratePhoto: vi.fn(),
    mutate: vi.fn(),
    isError: undefined,
};

const mockUser = {
    id: 'u1',
    name: 'Test User',
    email: 'test@example.com',
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    flatrate_level: undefined,
    roles: [],
};

function renderDeliveryView(galleryData = baseGalleryData) {
    return render(
        <MemoryRouter>
            <DeliveryView galleryData={galleryData} />
        </MemoryRouter>,
    );
}

describe('DeliveryView', () => {
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
            isCustomerManager: false,
            showTenantsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });
    });

    it('renders gallery name and header', () => {
        renderDeliveryView();
        const nameElements = screen.getAllByText('Test Gallery');
        expect(nameElements.length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Lade deine Bilder herunter.')).toBeInTheDocument();
    });

    it('renders photo grid with photos', () => {
        renderDeliveryView();
        expect(screen.getByAltText('Photo 1')).toBeInTheDocument();
        expect(screen.getByAltText('Photo 2')).toBeInTheDocument();
        expect(screen.getAllByText('Bild öffnen')).toHaveLength(2);
    });

    it('shows download button for staff user with full access', () => {
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
            isAdmin: false,
            isSuperAdmin: false,
            isPhotographer: true,
            isCustomerManager: false,
            showTenantsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        renderDeliveryView();
        expect(screen.getByText('Alle herunterladen (.zip)')).toBeInTheDocument();
    });

    it('hides download button for unauthorized user', () => {
        renderDeliveryView();
        expect(screen.queryByText('Alle herunterladen (.zip)')).not.toBeInTheDocument();
    });

    it('shows empty state when no photos are present', () => {
        const emptyData = {
            ...baseGalleryData,
            photos: [],
            totalPhotos: 0,
        };

        renderDeliveryView(emptyData);
        expect(screen.getByText('Noch keine Bilder vorhanden')).toBeInTheDocument();
        expect(screen.getByText('Der Fotograf hat noch keine Bilder für diese Galerie freigegeben.')).toBeInTheDocument();
    });

    it('shows "Mehr laden" button when more photos available', () => {
        const paginatedData = {
            ...baseGalleryData,
            isReachingEnd: false,
            size: 1,
        };

        renderDeliveryView(paginatedData);
        expect(screen.getByText('Mehr laden')).toBeInTheDocument();
    });

    it('shows notification toggle for authenticated user', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: mockUser,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        renderDeliveryView();
        expect(screen.getByText('E-Mail Updates')).toBeInTheDocument();
    });
});
