import useSWRInfinite from 'swr/infinite';
import {fetcher} from '../api';
import {Gallery} from './useGalleries';

export interface Photo {
    id: string;
    gallery_id: string;
    filename: string;
    lr_uuid: string;
    width: number;
    height: number;
    url: string;
    thumb_url: string;
    srcset?: string;
    rating: number;
    comment: string;
    gallery?: Gallery;
    title?: string;
    description?: string;
    artist?: string;
    headline?: string;
    keywords?: string;
    location?: string;
    city?: string;
    state?: string;
    country?: string;
    iso_country?: string;
}

export interface PaginatedGalleryResponse {
    gallery: Gallery;
    photos: Photo[];
    current_page: number;
    last_page: number;
    total: number;
    downloads_count: number;
    notified_count: number;
    wants_notifications: boolean;
    breadcrumbs: {name: string, full_path: string, type: string}[];
}

export function useGallery(slug: string | undefined) {
    const getKey = (pageIndex: number, previousPageData: PaginatedGalleryResponse | null) => {
        if (!slug) return null;
        if (previousPageData && previousPageData.current_page >= previousPageData.last_page) return null;
        return "/api/galleries/" + slug + "?page=" + (pageIndex + 1);
    };

    const {data, error, isLoading, size, setSize, mutate} = useSWRInfinite<PaginatedGalleryResponse>(
        getKey,
        fetcher,
        {
            refreshInterval: (latestData) => {
                const isLive = latestData?.some(page => page.gallery.is_live);
                return isLive ? 10000 : 0;
            }
        }
    );

    const photos = data ? data.flatMap(page => page.photos) : [];
    const gallery = data?.[0]?.gallery;

    const isReachingEnd = data && data[data.length - 1]?.current_page >= data[data.length - 1]?.last_page;
    const totalPhotos = data?.[0]?.total || 0;
    const wantsNotifications = data?.[0]?.wants_notifications || false;
    const breadcrumbs = data?.[0]?.breadcrumbs || [];

    // ✨ FIX: notified_count aus der API-Antwort extrahieren
    const notifiedCount = data?.[0]?.notified_count || 0;
    const downloadsCount = data?.[0]?.downloads_count || 0;

    const ratePhoto = async (photoId: string, rating: number, comment: string = '') => {
        if (data) {
            const newData = data.map(page => ({
                ...page,
                photos: page.photos.map(p => p.id === photoId ? {...p, rating, comment} : p)
            }));
            mutate(newData, { revalidate: false });
        }

        const res = await fetch("/api/photos/" + photoId + "/rate", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({rating, comment}),
            credentials: 'include'
        });

        if (res.status === 401) {
            const modal = document.getElementById('login_modal') as HTMLDialogElement;
            if (modal) {
                modal.showModal();
            } else {
                window.location.href = '/login';
            }
            mutate();
            return;
        }

        mutate();
    };

    return {
        gallery,
        photos,
        downloadsCount,
        notified_count: notifiedCount, // ✨ FIX: Rückgabe für die UI
        totalPhotos,
        isLoading,
        isError: error,
        ratePhoto,
        size,
        setSize,
        isReachingEnd,
        wantsNotifications,
        breadcrumbs,
        toggleOptIn: async (id: string, val: boolean) => {
            await fetch('/api/galleries/'+id+'/opt-in', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({wants_notifications: val}),
                credentials: 'include'
            });
            mutate();
        },
        mutate
    };
}