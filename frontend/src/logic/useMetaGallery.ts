import useSWRInfinite from 'swr/infinite';
import {fetcher} from '../api';
import {Photo} from './useGallery';
import {GalleryGroup} from './useGalleries';

export interface PaginatedMetaGalleryResponse {
    group: GalleryGroup;
    photos: Photo[];
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
    const isReachingEnd = data && data[data.length - 1]?.current_page >= data[data.length - 1]?.last_page;
    const downloadsCount = data?.[0]?.downloads_count || 0;

    return {group, photos, downloadsCount, isLoading, isError: error, size, setSize, isReachingEnd, mutate};
}
