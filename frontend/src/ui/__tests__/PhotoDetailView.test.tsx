import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen} from '@testing-library/react';
import useSWR from 'swr';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import {usePhoto} from '../../logic/usePhoto';
import {useAI} from '../../logic/useAI';
import {useLicensingMode} from '../../logic/useLicensingMode';
import {useUI} from '../components/UIContext';
import PhotoDetailView from '../PhotoDetailView';
import {renderWithProviders} from '../../test-setup';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useParams: () => ({id: 'displayed-photo'}),
    };
});

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../logic/usePhoto', () => ({
    usePhoto: vi.fn(),
}));

vi.mock('../../logic/useAI', () => ({
    useAI: vi.fn(),
}));

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
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

vi.mock('../components/IptcMetadataEditor', () => ({
    default: ({children}: {children: React.ReactNode}) => <section>{children}</section>,
}));

vi.mock('../components/PhotoHistoryModal', () => ({
    default: () => null,
}));

vi.mock('../client/components/LicenseSelectorCard', () => ({
    default: () => <div data-testid="scope-selector" />,
}));

vi.mock('../client/components/VolumeLicensingCard', () => ({
    default: () => <div data-testid="volume-selector" />,
}));

const photo = {
    id: 'displayed-photo',
    gallery_id: 'displayed-gallery',
    filename: 'displayed.jpg',
    lr_uuid: 'displayed-uuid',
    width: 1200,
    height: 800,
    url: '/displayed.jpg',
    thumb_url: '/displayed-thumb.jpg',
    title: 'Displayed photo',
    rating: 0,
    comment: '',
    gallery: {
        id: 'displayed-gallery',
        name: 'Displayed gallery',
        slug: 'displayed-gallery',
        full_path: 'displayed-gallery',
        type: 'delivery' as const,
        is_live: false,
        is_public: true,
    },
};

describe('PhotoDetailView licensing context', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useSWR).mockReturnValue({
            data: {
                photo,
                breadcrumbs: [],
                downloads_count: 0,
            },
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);
        vi.mocked(useAuth).mockReturnValue({
            user: {
                id: 'user-1',
                name: 'Test User',
                email: 'test@example.com',
                is_super_admin: false,
                is_admin: false,
                is_photographer: false,
                is_pending: false,
                can_edit_metadata: false,
                roles: ['client'],
                guest_id: null,
                billing_name: null,
                billing_company: null,
                billing_street: null,
                billing_zip: null,
                billing_city: null,
                brand: null,
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
        vi.mocked(usePhoto).mockReturnValue({
            updateMetadata: vi.fn().mockResolvedValue({success: true}),
            getVersions: vi.fn().mockResolvedValue([]),
            revertMetadata: vi.fn().mockResolvedValue({success: true}),
            deletePhoto: vi.fn().mockResolvedValue(undefined),
        });
        vi.mocked(useAI).mockReturnValue({
            isAvailable: false,
            mode: 'unavailable',
            modelId: null,
            generateMetadata: vi.fn().mockResolvedValue({}),
            generateMetadataFromText: vi.fn().mockResolvedValue({}),
            updateBaseUrl: vi.fn(),
        });
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('passes the displayed photo gallery ID when resolving a scope override', () => {
        vi.mocked(useLicensingMode).mockReturnValue('scope_licensing');

        renderWithProviders(<PhotoDetailView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('displayed-gallery');
        expect(screen.getByTestId('scope-selector')).toBeInTheDocument();
        expect(screen.queryByTestId('volume-selector')).not.toBeInTheDocument();
    });

    it('passes the displayed photo gallery ID when resolving a volume override', () => {
        vi.mocked(useLicensingMode).mockReturnValue('volume_licensing');

        renderWithProviders(<PhotoDetailView />);

        expect(useLicensingMode).toHaveBeenLastCalledWith('displayed-gallery');
        expect(screen.getByTestId('volume-selector')).toBeInTheDocument();
        expect(screen.queryByTestId('scope-selector')).not.toBeInTheDocument();
    });

    it('replaces the scope fallback with the volume card when licensing resolves', async () => {
        let mode: 'scope_licensing' | 'volume_licensing' = 'scope_licensing';
        vi.mocked(useLicensingMode).mockImplementation(() => mode);

        const view = renderWithProviders(<PhotoDetailView />);
        expect(screen.getByTestId('scope-selector')).toBeInTheDocument();

        mode = 'volume_licensing';
        view.rerender(<PhotoDetailView />);

        expect(await screen.findByTestId('volume-selector')).toBeInTheDocument();
        expect(screen.queryByTestId('scope-selector')).not.toBeInTheDocument();
    });
});
