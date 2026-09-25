import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {usePermissions} from '../../logic/usePermissions';
import {useProtectedGalleries, type GalleryGroupExtraOpts} from '../../logic/useGalleries';
import {useMetaGallery} from '../../logic/useMetaGallery';
import {useGalleryLicensing} from '../../logic/useVolumeLicensing';
import ManagementMetaGalleryView from '../management/ManagementMetaGalleryView';
import {galleryPricingSourcesFromPhotos} from '../../logic/metaGalleryPricing';
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

vi.mock('../../logic/useVolumeLicensing', async () => {
    const actual = await vi.importActual<typeof import('../../logic/useVolumeLicensing')>('../../logic/useVolumeLicensing');
    return {
        ...actual,
        useGalleryLicensing: vi.fn(),
    };
});

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
        gallery_group_id: 'first-child-group',
    },
};

const secondPhoto = {
    ...photo,
    id: 'meta-photo-2',
    gallery_id: 'second-child-gallery',
    url: '/meta-2.jpg',
    thumb_url: '/meta-thumb-2.jpg',
    gallery: {
        ...photo.gallery,
        id: 'second-child-gallery',
        name: 'Second child gallery',
        gallery_group_id: 'second-child-group',
    },
};

const thirdPhoto = {
    ...photo,
    id: 'meta-photo-3',
    gallery_id: 'scope-child-gallery',
    url: '/meta-3.jpg',
    thumb_url: '/meta-thumb-3.jpg',
    gallery: {
        ...photo.gallery,
        id: 'scope-child-gallery',
        name: 'Scope child gallery',
        gallery_group_id: 'scope-child-group',
    },
};

const defaultGalleryLicensing = {
    isVolumePricing: true,
    groups: [
        {
            key: 'volume_licensing|preset-a',
            licensingMode: 'volume_licensing' as const,
            presetId: 'preset-a',
            presetName: 'Preset A',
            galleryIds: ['first-displayed-gallery'],
            galleryGroupIds: ['first-child-group'],
            photoCount: 1,
            totalCents: 7000,
            pricePerItemCents: 7000,
            tiers: [{minQuantity: 0, priceCents: 7000}],
            tierIndex: 0,
            isMaxTier: true,
            nextTierCount: 0,
            nextTierLabel: '',
            isVolumePricing: true,
        },
    ],
    volumeSubtotalCents: 7000,
    isLoading: false,
};

describe('ManagementMetaGalleryView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useGalleryLicensing).mockReturnValue(defaultGalleryLicensing);
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
            galleryPricingSources: undefined,
            downloadsCount: 0,
            isLoading: false,
            isError: undefined,
            size: 1,
            setSize: vi.fn(),
            isReachingEnd: true,
            mutate: mutateMetaGallery,
        });
    });

    it('resolves the actual child gallery sources rather than the meta-group ID', () => {
        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useGalleryLicensing).toHaveBeenLastCalledWith([
            {galleryId: 'first-displayed-gallery', galleryGroupId: 'first-child-group', galleryName: 'First displayed gallery', photoCount: 1},
        ]);
        expect(useGalleryLicensing).not.toHaveBeenCalledWith(expect.arrayContaining([
            expect.objectContaining({galleryId: 'meta-group'}),
        ]));
        expect(screen.getByRole('tab', {name: 'Coupons'})).toBeInTheDocument();
    });

    it('uses the complete server source summary instead of paginated photo counts', () => {
        const current = vi.mocked(useMetaGallery)('meta-group');
        if (!current) throw new Error('Meta-gallery fixture is missing.');
        vi.mocked(useMetaGallery).mockReturnValue({
            ...current,
            galleryPricingSources: [{
                gallery_id: 'server-gallery',
                gallery_group_id: 'server-child-group',
                gallery_name: 'Complete server gallery',
                photo_count: 12,
            }],
        });
        vi.mocked(useGalleryLicensing).mockReturnValue({
            isVolumePricing: true,
            groups: [{
                key: 'volume_licensing|server-preset',
                licensingMode: 'volume_licensing',
                presetId: 'server-preset',
                presetName: 'Server preset',
                galleryIds: ['server-gallery'],
                galleryGroupIds: ['server-child-group'],
                photoCount: 12,
                totalCents: 30000,
                pricePerItemCents: 2500,
                tiers: [{minQuantity: 0, priceCents: 3000}, {minQuantity: 10, priceCents: 2500}],
                tierIndex: 1,
                isMaxTier: true,
                nextTierCount: 0,
                nextTierLabel: '',
                isVolumePricing: true,
            }],
            volumeSubtotalCents: 30000,
            isLoading: false,
        });

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useGalleryLicensing).toHaveBeenLastCalledWith([{
            galleryId: 'server-gallery',
            galleryGroupId: 'server-child-group',
            galleryName: 'Complete server gallery',
            photoCount: 12,
        }]);
        expect(screen.getByTestId('meta-gallery-pricing-group-server-preset')).toHaveTextContent(
            'Complete server gallery',
        );
        expect(screen.getByTestId('meta-gallery-pricing-group-server-preset')).toHaveTextContent('12 Bilder');
        expect(screen.getByTestId('meta-gallery-volume-subtotal')).toHaveTextContent('300.00 €');
    });

    it('renders grouped totals for mixed child galleries and keeps volume coupons available', () => {
        const current = vi.mocked(useMetaGallery)('meta-group');
        if (!current) throw new Error('Meta-gallery fixture is missing.');
        vi.mocked(useMetaGallery).mockReturnValue({
            ...current,
            photos: [photo, {...photo, id: 'meta-photo-1b'}, secondPhoto, thirdPhoto],
        });
        vi.mocked(useGalleryLicensing).mockReturnValue({
            isVolumePricing: true,
            groups: [
                {
                    key: 'volume_licensing|preset-a',
                    licensingMode: 'volume_licensing',
                    presetId: 'preset-a',
                    presetName: 'Preset A',
                    galleryIds: ['first-displayed-gallery'],
                    galleryGroupIds: ['first-child-group'],
                    photoCount: 2,
                    totalCents: 8000,
                    pricePerItemCents: 4000,
                    tiers: [{minQuantity: 0, priceCents: 5000}, {minQuantity: 2, priceCents: 4000}],
                    tierIndex: 1,
                    isMaxTier: true,
                    nextTierCount: 0,
                    nextTierLabel: '',
                    isVolumePricing: true,
                },
                {
                    key: 'volume_licensing|preset-b',
                    licensingMode: 'volume_licensing',
                    presetId: 'preset-b',
                    presetName: 'Preset B',
                    galleryIds: ['second-child-gallery'],
                    galleryGroupIds: ['second-child-group'],
                    photoCount: 1,
                    totalCents: 7000,
                    pricePerItemCents: 7000,
                    tiers: [{minQuantity: 0, priceCents: 7000}],
                    tierIndex: 0,
                    isMaxTier: true,
                    nextTierCount: 0,
                    nextTierLabel: '',
                    isVolumePricing: true,
                },
                {
                    key: 'scope_licensing|default',
                    licensingMode: 'scope_licensing',
                    presetId: 'default',
                    presetName: null,
                    galleryIds: ['scope-child-gallery'],
                    galleryGroupIds: ['scope-child-group'],
                    photoCount: 1,
                    totalCents: null,
                    pricePerItemCents: null,
                    tiers: [],
                    tierIndex: 0,
                    isMaxTier: false,
                    nextTierCount: 0,
                    nextTierLabel: '',
                    isVolumePricing: false,
                },
            ],
            volumeSubtotalCents: 15000,
            isLoading: false,
        });

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useGalleryLicensing).toHaveBeenLastCalledWith([
            {galleryId: 'first-displayed-gallery', galleryGroupId: 'first-child-group', galleryName: 'First displayed gallery', photoCount: 2},
            {galleryId: 'second-child-gallery', galleryGroupId: 'second-child-group', galleryName: 'Second child gallery', photoCount: 1},
            {galleryId: 'scope-child-gallery', galleryGroupId: 'scope-child-group', galleryName: 'Scope child gallery', photoCount: 1},
        ]);
        expect(screen.getByRole('tab', {name: 'Coupons'})).toBeInTheDocument();
        expect(screen.getByRole('article', {
            name: 'Preisgruppe First displayed gallery',
        })).toHaveTextContent('80.00 €');
        expect(screen.getByTestId('meta-gallery-pricing-group-preset-a')).toHaveTextContent('80.00 €');
        expect(screen.getByTestId('meta-gallery-pricing-group-preset-b')).toHaveTextContent('70.00 €');
        expect(screen.getByTestId('meta-gallery-pricing-group-default')).toHaveTextContent('Scope-Lizenz');
        expect(screen.getByTestId('meta-gallery-volume-subtotal')).toHaveTextContent('150.00 €');
    });

    it('aggregates repeated photos by child gallery and group', () => {
        expect(galleryPricingSourcesFromPhotos([
            photo,
            {...photo, id: 'meta-photo-1b'},
            secondPhoto,
        ])).toEqual([
            {galleryId: 'first-displayed-gallery', galleryGroupId: 'first-child-group', galleryName: 'First displayed gallery', photoCount: 2},
            {galleryId: 'second-child-gallery', galleryGroupId: 'second-child-group', galleryName: 'Second child gallery', photoCount: 1},
        ]);
    });

    it('includes an empty volume child from the management tree, not only loaded photos', () => {
        const currentGalleryHook = vi.mocked(useProtectedGalleries)();
        vi.mocked(useProtectedGalleries).mockReturnValue({
            ...currentGalleryHook,
            tree: {
                groups: [{
                    id: 'meta-group',
                    name: 'Meta group',
                    parent_id: null,
                    galleries: [{
                        id: 'first-displayed-gallery',
                        name: 'First displayed gallery',
                        slug: 'first-displayed-gallery',
                        full_path: 'first-displayed-gallery',
                        type: 'delivery',
                        is_live: false,
                        is_public: true,
                        gallery_group_id: 'first-child-group',
                    }, {
                        id: 'empty-volume-gallery',
                        name: 'Empty volume gallery',
                        slug: 'empty-volume-gallery',
                        full_path: 'empty-volume-gallery',
                        type: 'delivery',
                        is_live: false,
                        is_public: true,
                        gallery_group_id: 'empty-volume-group',
                    }],
                }],
                root_galleries: [],
            },
        });
        const currentMetaHook = vi.mocked(useMetaGallery)('meta-group');
        vi.mocked(useMetaGallery).mockReturnValue({
            ...currentMetaHook,
            photos: [photo],
        });

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useGalleryLicensing).toHaveBeenLastCalledWith([
            {galleryId: 'first-displayed-gallery', galleryGroupId: 'first-child-group', galleryName: 'First displayed gallery', photoCount: 1},
            {galleryId: 'empty-volume-gallery', galleryGroupId: 'empty-volume-group', galleryName: 'Empty volume gallery', photoCount: 0},
        ]);
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

    it('keeps the meta-gallery tab on images when all child galleries are scope-licensed', () => {
        vi.mocked(useGalleryLicensing).mockReturnValue({
            isVolumePricing: false,
            groups: [],
            volumeSubtotalCents: 0,
            isLoading: false,
        });

        renderWithProviders(<ManagementMetaGalleryView />);

        expect(useGalleryLicensing).toHaveBeenLastCalledWith([
            {galleryId: 'first-displayed-gallery', galleryGroupId: 'first-child-group', galleryName: 'First displayed gallery', photoCount: 1},
        ]);
        expect(screen.queryByRole('tab', {name: 'Coupons'})).not.toBeInTheDocument();
    });
});
