import {act, screen, waitFor} from '@testing-library/react';
import {beforeEach, describe, expect, it, vi} from 'vitest';
import type {ReactNode} from 'react';
import type {User} from '../../../api';
import type {useGallery} from '../../../logic/useGallery';
import {useAuth} from '../../../logic/useAuth';
import {usePhotoSwipe} from '../../../logic/usePhotoSwipe';
import {useUI} from '../../components/UIContext';
import {renderWithProviders} from '../../../test-setup';
import SelectionView from '../SelectionView';

type PhotoSwipeInit = (lightbox: unknown) => void;

const photoSwipeState = vi.hoisted(() => ({
    onInit: null as PhotoSwipeInit | null,
}));

vi.mock('../../../logic/usePhotoSwipe', () => ({
    usePhotoSwipe: (options: {onInit?: PhotoSwipeInit}) => {
        photoSwipeState.onInit = options.onInit ?? null;
    },
}));

vi.mock('../../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../../api', () => ({
    apiMutate: vi.fn(),
}));

vi.mock('../../components/PageLayout', () => ({
    default: ({children}: {children: ReactNode}) => <main>{children}</main>,
}));

vi.mock('../../components/GalleryHeader', () => ({
    default: () => <header>Galleries</header>,
}));

vi.mock('../components/ResponsiveImage', () => ({
    default: ({alt}: {alt: string}) => <img src="/photo.jpg" alt={alt}/>,
}));

vi.mock('../components/GridPhotoActions', () => ({
    default: ({photo, ratePhoto}: {
        photo: {id: string};
        ratePhoto: (photoId: string, rating: number, comment: string) => Promise<void>;
    }) => (
        <button type="button" onClick={() => ratePhoto(photo.id, 5, 'Great!')}>
            Bewertung 5
        </button>
    ),
}));

vi.mock('../components/SelectionFilterBar', () => ({
    default: () => <div>Rating filters</div>,
}));

vi.mock('../components/NotificationsOptIn', () => ({
    default: () => null,
}));

const authenticatedUser: User = {
    id: 'user-1',
    name: 'Test User',
    email: 'user@example.com',
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    roles: [],
};

const ratePhoto = vi.fn<(photoId: string, rating: number, comment: string) => Promise<void>>().mockResolvedValue(undefined);
const showToast = vi.fn();
const confirm = vi.fn<(options: unknown) => Promise<boolean>>();

const galleryData: ReturnType<typeof useGallery> = {
    gallery: {
        id: 'gallery-1',
        name: 'Selection Gallery',
        slug: 'selection-gallery',
        full_path: 'selection-gallery',
        type: 'selection',
        is_live: false,
        is_public: true,
    },
    canManage: false,
    photos: [{
        id: 'photo-1',
        gallery_id: 'gallery-1',
        filename: 'photo.jpg',
        lr_uuid: 'uuid-1',
        width: 800,
        height: 600,
        url: '/photo.jpg',
        thumb_url: '/photo-thumb.jpg',
        title: 'Photo',
        rating: 0,
        comment: '',
    }],
    downloadsCount: 0,
    notified_count: 0,
    totalPhotos: 1,
    isLoading: false,
    isError: undefined,
    ratePhoto,
    size: 1,
    setSize: vi.fn(),
    isReachingEnd: true,
    wantsNotifications: false,
    breadcrumbs: [],
    toggleOptIn: vi.fn(),
    mutate: vi.fn(),
};

interface FakeLightbox {
    on: (eventName: string, listener: () => void) => void;
    pswp: {
        isOpen: boolean;
        currSlide: {data: {element: HTMLElement}};
        next: () => void;
    };
}

function createLightbox(element: HTMLElement) {
    const listeners = new Map<string, Set<() => void>>();
    const lightbox: FakeLightbox = {
        on: (eventName, listener) => {
            const eventListeners = listeners.get(eventName) ?? new Set<() => void>();
            eventListeners.add(listener);
            listeners.set(eventName, eventListeners);
        },
        pswp: {
            isOpen: true,
            currSlide: {data: {element}},
            next: vi.fn(),
        },
    };

    return {
        lightbox,
        emit: (eventName: string) => {
            listeners.get(eventName)?.forEach((listener) => listener());
        },
    };
}

function initializeKeyboardHandlers() {
    expect(photoSwipeState.onInit).not.toBeNull();
    const element = screen.getByRole('link', {name: 'Photo'});
    const {lightbox, emit} = createLightbox(element);

    act(() => photoSwipeState.onInit?.(lightbox));
    act(() => emit('beforeOpen'));

    return {lightbox, emit};
}

describe('SelectionView rating regressions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        ratePhoto.mockResolvedValue(undefined);
        photoSwipeState.onInit = null;
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm,
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('does not issue a rating request when a guest presses a number key', () => {
        renderWithProviders(<SelectionView galleryData={galleryData}/>);
        const {emit} = initializeKeyboardHandlers();

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', {key: '5', bubbles: true}));
        });

        expect(ratePhoto).not.toHaveBeenCalled();
        expect(screen.queryByRole('button', {name: 'Bewertung 5'})).not.toBeInTheDocument();
        act(() => emit('close'));
    });

    it('keeps the keyboard rating flow for an authenticated client', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: authenticatedUser,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        renderWithProviders(<SelectionView galleryData={galleryData}/>);
        const {lightbox, emit} = initializeKeyboardHandlers();

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', {key: '5', bubbles: true}));
        });

        expect(ratePhoto).toHaveBeenCalledWith('photo-1', 5, '');
        expect(lightbox.pswp.next).toHaveBeenCalledOnce();
        act(() => emit('close'));
    });

    it('surfaces a non-401 rating error in the global toast', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: authenticatedUser,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        ratePhoto.mockRejectedValueOnce(new Error('Rating service unavailable'));

        renderWithProviders(<SelectionView galleryData={galleryData}/>);

        screen.getByRole('button', {name: 'Bewertung 5'}).click();

        await waitFor(() => {
            expect(showToast).toHaveBeenCalledWith('error', 'Rating service unavailable');
        });
    });
});
