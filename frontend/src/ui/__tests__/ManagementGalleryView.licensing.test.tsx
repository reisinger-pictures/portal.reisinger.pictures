import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen} from '@testing-library/react';
import {useGallery} from '../../logic/useGallery';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import {useLicensingMode} from '../../logic/useLicensingMode';
import ManagementGalleryView from '../management/ManagementGalleryView';
import {renderWithProviders} from '../../test-setup';

vi.mock('../../logic/useGallery', () => ({useGallery: vi.fn()}));
vi.mock('../../logic/useGalleries', () => ({useProtectedGalleries: vi.fn()}));
vi.mock('../../logic/useAuth', () => ({useAuth: vi.fn()}));
vi.mock('../../logic/usePermissions', () => ({usePermissions: vi.fn()}));
vi.mock('../../logic/useLicensingMode', () => ({useLicensingMode: vi.fn()}));
vi.mock('../../logic/usePhotoSwipe', () => ({usePhotoSwipe: vi.fn()}));

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useParams: () => ({'*': 'displayed-gallery'}),
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

vi.mock('../components/PageLayout', () => ({
    default: ({children}: {children: React.ReactNode}) => <main>{children}</main>,
}));
vi.mock('../components/ErrorMessage', () => ({
    default: ({message}: {message: string}) => <div>{message}</div>,
}));
vi.mock('../components/GalleryHeader', () => ({default: () => <div />}));
vi.mock('../components/GalleryModals', () => ({default: () => null}));
vi.mock('../components/EmptyState', () => ({default: () => null}));
vi.mock('../management/components/GalleryAccessModal', () => ({default: () => null}));
vi.mock('../management/components/EmailComposerModal', () => ({default: () => null}));
vi.mock('../management/components/InviteModal', () => ({default: () => null}));
vi.mock('../management/components/UploadDropzone', () => ({default: () => null}));
vi.mock('../management/components/RatingStatusModal', () => ({default: () => null}));
vi.mock('../management/components/GalleryMetadataDefaultsModal', () => ({default: () => null}));
vi.mock('../management/components/ManagementGalleryActions', () => ({default: () => null}));
vi.mock('../management/components/PhotographerTeamModal', () => ({default: () => null}));
vi.mock('../management/components/AIBatchEditModal', () => ({default: () => null}));
vi.mock('../management/components/GalleryCouponsTab', () => ({default: () => null}));

const gallery = {
    id: 'displayed-gallery-id',
    name: 'Displayed gallery',
    slug: 'displayed-gallery',
    full_path: 'displayed-gallery',
    type: 'delivery' as const,
    is_live: false,
    is_public: true,
};

const galleryData = {
    gallery,
    canManage: true,
    photos: [],
    totalPhotos: 0,
    ratePhoto: vi.fn(),
    wantsNotifications: false,
    toggleOptIn: vi.fn(),
    downloadsCount: 0,
    notified_count: 0,
    isLoading: false,
    isError: undefined,
    size: 1,
    setSize: vi.fn(),
    isReachingEnd: true,
    mutate: vi.fn(),
    breadcrumbs: [],
};

describe('ManagementGalleryView licensing context', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useGallery).mockReturnValue(galleryData);
        vi.mocked(useProtectedGalleries).mockReturnValue({
            tree: {groups: [], root_galleries: []},
            isLoading: false,
            isError: undefined,
            mutate: vi.fn(),
            createGroup: vi.fn(),
            updateGroup: vi.fn(),
            deleteGroup: vi.fn(),
            createGallery: vi.fn(),
            updateGallery: vi.fn(),
            deleteGallery: vi.fn(),
        });
        vi.mocked(useAuth).mockReturnValue({
            user: {
                id: 'admin-1',
                name: 'Admin',
                email: 'admin@example.com',
                is_super_admin: false,
                is_admin: true,
                is_photographer: true,
                is_pending: false,
                can_edit_metadata: true,
                roles: ['admin'],
                guest_id: null,
                billing_name: null,
                billing_company: null,
                billing_street: null,
                billing_zip: null,
                billing_city: null,
                brand: 'rp',
                is_cross_brand: false,
                is_org_admin: false,
                is_power_user: false,
                can_purchase_upgrades: false,
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
    });

    it('passes the displayed gallery ID for a scope override', () => {
        vi.mocked(useLicensingMode).mockReturnValue('scope_licensing');

        renderWithProviders(<ManagementGalleryView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('displayed-gallery-id');
        expect(screen.queryByRole('tab', {name: 'Coupons'})).not.toBeInTheDocument();
    });

    it('passes the displayed gallery ID for a volume override', () => {
        vi.mocked(useLicensingMode).mockReturnValue('volume_licensing');

        renderWithProviders(<ManagementGalleryView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('displayed-gallery-id');
        expect(screen.getByRole('tab', {name: 'Coupons'})).toBeInTheDocument();
    });
});
