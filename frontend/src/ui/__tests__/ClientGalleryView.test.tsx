import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import ClientGalleryView from '../client/ClientGalleryView';
import { useGallery } from '../../logic/useGallery';
import { useAuth } from '../../logic/useAuth';

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useParams: () => ({ '*': 'test-gallery' }),
    };
});

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/useGallery', () => ({
    useGallery: vi.fn(),
}));

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

vi.mock('../client/DeliveryView', () => ({
    default: () => <div data-testid="delivery-view" />,
}));

vi.mock('../client/SelectionView', () => ({
    default: () => <div data-testid="selection-view" />,
}));

const mockGallery = {
    id: 'g1',
    name: 'Test Gallery',
    slug: 'test-gallery',
    full_path: 'test-gallery',
    type: 'delivery' as const,
    is_live: false,
    is_public: true,
};

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
    srcset: undefined,
    gallery: undefined,
    is_hidden: undefined,
    effective_is_hidden: undefined,
    captured_at: undefined,
};

const baseGalleryData = {
    gallery: mockGallery,
    photos: [mockPhoto],
    isLoading: false,
    totalPhotos: 1,
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

function renderClientGalleryView() {
    return renderWithProviders(
        <MemoryRouter>
            <ClientGalleryView />
        </MemoryRouter>,
    );
}

describe('ClientGalleryView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useAuth).mockReturnValue({
            user: { id: 'u1', name: 'Test', email: 'test@test.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: [] },
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        vi.mocked(useGallery).mockReturnValue(baseGalleryData);
    });

    it('shows loading spinner while gallery data is loading', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isLoading: true,
            photos: [],
        });

        renderClientGalleryView();
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('shows error message when gallery is not found', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isError: new Error('Not found'),
            gallery: undefined,
            photos: [],
            isLoading: false,
        });

        renderClientGalleryView();
        expect(screen.getByTestId('error-message')).toBeInTheDocument();
        expect(screen.getByText('Galerie nicht gefunden oder Zugriff verweigert.')).toBeInTheDocument();
    });

    it('redirects to home on 401 unauthorized error', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            isError: new Error('Unauthenticated'),
            gallery: undefined,
            photos: [],
            isLoading: false,
        });

        renderClientGalleryView();
        expect(screen.queryByTestId('delivery-view')).not.toBeInTheDocument();
        expect(screen.queryByTestId('selection-view')).not.toBeInTheDocument();
    });

    it('renders DeliveryView for delivery-type gallery', () => {
        vi.mocked(useGallery).mockReturnValue(baseGalleryData);

        renderClientGalleryView();
        expect(screen.getByTestId('delivery-view')).toBeInTheDocument();
        expect(screen.queryByTestId('selection-view')).not.toBeInTheDocument();
    });

    it('renders SelectionView for selection-type gallery', () => {
        vi.mocked(useGallery).mockReturnValue({
            ...baseGalleryData,
            gallery: { ...mockGallery, type: 'selection' as const },
        });

        renderClientGalleryView();
        expect(screen.getByTestId('selection-view')).toBeInTheDocument();
        expect(screen.queryByTestId('delivery-view')).not.toBeInTheDocument();
    });
});
