import useSWRInfinite from 'swr/infinite';
import {apiMutate, fetcher} from '../api';
import {Gallery} from './useGalleries';
import {IptcData} from './usePhoto';

export interface Photo extends IptcData {
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
    is_hidden?: boolean;
    effective_is_hidden?: boolean;
    captured_at?: string;
}

export interface PaginatedGalleryResponse {
    gallery: Gallery;
    can_manage: boolean;
    photos: Photo[];
    current_page: number;
    last_page: number;
    total: number;
    downloads_count: number;
    notified_count: number;
    wants_notifications: boolean;
    breadcrumbs: {name: string, full_path: string, type: string}[];
}

function hasErrorStatus(error: unknown, status: number): boolean {
    return typeof error === 'object' && error !== null && 'status' in error && error.status === status;
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
    const canManage = data?.[0]?.can_manage || false;

    const isReachingEnd = data && data[data.length - 1]?.current_page >= data[data.length - 1]?.last_page;
    const totalPhotos = data?.[0]?.total || 0;
    const wantsNotifications = data?.[0]?.wants_notifications || false;
    const breadcrumbs = data?.[0]?.breadcrumbs || [];

    const notifiedCount = data?.[0]?.notified_count || 0;
    const downloadsCount = data?.[0]?.downloads_count || 0;

    const ratePhoto = async (photoId: string, rating: number, comment: string = '') => {
        const oldData = data;

        try {
            if (data) {
                const newData = data.map(page => ({
                    ...page,
                    photos: page.photos.map(p => p.id === photoId ? {...p, rating, comment} : p)
                }));
                await mutate(newData, {revalidate: false});
            }

            await apiMutate('/api/photos/' + photoId + '/rate', 'POST', {rating, comment});
        } catch (error) {
            if (oldData) {
                try {
                    await mutate(oldData, {revalidate: false});
                } catch {
                    // The API error below is the actionable failure. A local
                    // cache rollback failure must not replace it for callers.
                }
            }

            if (hasErrorStatus(error, 401)) {
                window.location.replace('/');
                return;
            }

            throw error;
        }
    };


    return {
        gallery, canManage, photos, downloadsCount, notified_count: notifiedCount, totalPhotos, isLoading, isError: error,
        ratePhoto, size, setSize, isReachingEnd, wantsNotifications, breadcrumbs,
        toggleOptIn: async (id: string, val: boolean) => {
            await apiMutate(`/api/galleries/${id}/opt-in`, 'POST', {wants_notifications: val});
            await mutate();
        },
        mutate
    };
}
