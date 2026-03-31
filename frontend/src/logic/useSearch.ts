import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from './useGallery';
import {Gallery} from './useGalleries';

export interface SearchResults {
    galleries: Gallery[];
    photos: Photo[];
}

export function useSearch(query: string, personal: boolean = false, skipEmpty: boolean = false) {
    const shouldFetch = !(skipEmpty && query.trim() === '');
    const key = shouldFetch ? `/api/search?q=${encodeURIComponent(query)}${personal ? '&personal=true' : ''}` : null;

    const {data, error, isLoading} = useSWR<SearchResults>(
        key,
        fetcher,
        {
            revalidateOnFocus: false,
            keepPreviousData: true
        }
    );

    return { results: data, isLoading, isError: error };
}
