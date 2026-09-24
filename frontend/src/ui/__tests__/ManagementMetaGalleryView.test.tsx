import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {usePermissions} from '../../logic/usePermissions';
import {useProtectedGalleries, type GalleryGroupExtraOpts} from '../../logic/useGalleries';
import {useMetaGallery} from '../../logic/useMetaGallery';
import {useLicensingMode} from '../../logic/useLicensingMode';
import ManagementMetaGalleryView from '../management/ManagementMetaGalleryView';
import {renderWithProviders} from '../../test-setup';

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useParams: () => ({id: 'meta-group'}),
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../logic/useGalleries', () => ({
    useProtectedGalleries: vi.fn(),
}));

vi.mock('../../logic/useMetaGallery', () => ({
    useMetaGallery: vi.fn(),
}));

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: vi.fn(),
}));

vi.mock('../../logic/usePhotoSwipe', () => ({
    usePhotoSwipe: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({children}: {children: React.ReactNode}) => <main>{children}</main>,
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({message}: {message: string}) => <div>{message}</div>,
}));

vi.mock('../components/ResponsiveImage', () => ({
    default: ({alt}: {alt: string}) => <img src="/photo.jpg" alt={alt} />,
}));

vi.mock('../components/GalleryModals', () => ({
    default: ({
        editingGroup,
        onUpdateGroup,
    }: {
        editingGroup?: {id: string; orgs?: Array<{id: string; name: string}>} | null;
        onUpdateGroup: (
            id: string,
            name: string,
            slug: string,
            isPublic: boolean | null,
            parentId?: string | null,
            extraOpts?: GalleryGroupExtraOpts
        ) => Promise<void>;
    }) => (
        <>
            <output data-testid="meta-editing-orgs">
                {editingGroup?.orgs?.map(org => org.id).join(',') ?? ''}
            </output>
            <button
                type="button"
                onClick={() => {
                    void onUpdateGroup(
                        editingGroup?.id ?? '',
                        'Updated group',
                        'updated-group',
                        false,
                        null,
                        {org_id: 'org-2'}
                    );
                }}
            >
                Save meta group
            </button>
        </>
    )
}));

vi.mock('../components/EmptyState', () => ({
    default: () => null,
}));

vi.mock('../management/components/GalleryGroupCouponsTab', () => ({
    default: () => <div data-testid="group-coupons" />,
}));

const updateGroup = vi.fn().mockResolvedValue(undefined);
const mutateMetaGallery = vi.fn();

const photo = {
    id: 'meta-photo',
    gallery_id: 'first-displayed-gallery',
    filename: 'meta.jpg',
    lr_uuid: 'meta-uuid',
    width: 1200,
    height: 800,
    url: '/meta.jpg',
    thumb_url: '/meta-thumb.jpg',
    title: 'Meta photo',
    rating: 0,
    comment: '',
    gallery: {
        id: 'first-displayed-gallery',
        name: 'First displayed gallery',
        slug: 'first-displayed-gallery',
        full_path: 'first-displayed-gallery',
        type: 'delivery' as const,
        is_live: false,
        is_public: true,
    },
};

describe('ManagementMetaGalleryView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
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
        vi.mocked(useProtectedGalleries).mockReturnValue({
            tree: {groups: [], root_galleries: []},
            isLoading: false,
            isError: undefined,
            mutate: vi.fn(),
            createGroup: vi.fn(),
            updateGroup,
            deleteGroup: vi.fn(),
            createGallery: vi.fn(),
            updateGallery: vi.fn(),
            deleteGallery: vi.fn(),
        });
        vi.mocked(useMetaGallery).mockReturnValue({
            group: {
                id: 'meta-group',
                name: 'Meta group',
                parent_id: null,
                orgs: [{id: 'org-1', name: 'Org Eins'}],
            },
            photos: [photo],
            downloadsCount: 0,
            isLoading: false,
            isError: undefined,
            size: 1,
            setSize: vi.fn(),
            isReachingEnd: true,
            mutate: mutateMetaGallery,
        });
    });

    it('uses an actual displayed photo gallery ID rather than the meta-group ID', () => {
        vi.mocked(useLicensingMode).mockReturnValue('volume_licensing');

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('first-displayed-gallery');
        expect(useLicensingMode).not.toHaveBeenCalledWith('meta-group');
        expect(screen.getByRole('tab', {name: 'Coupons'})).toBeInTheDocument();
    });

    it('forwards organization extra options from the meta route', async () => {
        const user = userEvent.setup();

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(screen.getByTestId('meta-editing-orgs')).toHaveTextContent('org-1');

        await user.click(screen.getByRole('button', {name: 'Save meta group'}));

        await waitFor(() => {
            expect(updateGroup).toHaveBeenCalledWith(
                'meta-group',
                'Updated group',
                'updated-group',
                false,
                null,
                {org_id: 'org-2'}
            );
        });
        expect(mutateMetaGallery).toHaveBeenCalledTimes(1);
    });

    it('keeps the meta-gallery tab on images for a scope override', () => {
        vi.mocked(useLicensingMode).mockReturnValue('scope_licensing');

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('first-displayed-gallery');
        expect(screen.queryByRole('tab', {name: 'Coupons'})).not.toBeInTheDocument();
    });
});
