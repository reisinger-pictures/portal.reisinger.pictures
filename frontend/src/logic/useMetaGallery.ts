import useSWRInfinite from 'swr/infinite';
import {fetcher} from '../api';
import {Photo} from './useGallery';
import {GalleryGroup} from './useGalleries';

export interface GalleryPricingSourcePayload {
    gallery_id: string;
    gallery_group_id: string | null;
    gallery_name: string;
    photo_count: number;
}

export interface PaginatedMetaGalleryResponse {
    group: GalleryGroup;
    photos: Photo[];
    /** Complete authorized child-gallery pricing inputs, independent of pagination. */
    gallery_pricing_sources?: GalleryPricingSourcePayload[];
    current_page: number;
    last_page: number;
    total: number;
    downloads_count: number;
}

export function useMetaGallery(id: string | undefined) {
    const getKey = (pageIndex: number, previousPageData: PaginatedMetaGalleryResponse | null) => {
        if (!id) return null;
        if (previousPageData && previousPageData.current_page >= previousPageData.last_page) return null;
        return "/api/management/gallery-groups/" + id + "?page=" + (pageIndex + 1);
    };

    const {data, error, isLoading, mutate, size, setSize} = useSWRInfinite<PaginatedMetaGalleryResponse>(
        getKey, fetcher
    );

    const photos = data ? data.flatMap(page => page.photos) : [];
    const group = data?.[0]?.group;
    const galleryPricingSources = data?.[0]?.gallery_pricing_sources;
    const isReachingEnd = data && data[data.length - 1]?.current_page >= data[data.length - 1]?.last_page;
    const downloadsCount = data?.[0]?.downloads_count || 0;

    return {
        group,
        photos,
        galleryPricingSources,
        downloadsCount,
        isLoading,
        isError: error,
        size,
        setSize,
        isReachingEnd,
        mutate,
    };
}
