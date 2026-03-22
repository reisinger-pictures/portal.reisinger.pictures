import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from './useGallery';
import {Gallery} from './useGalleries';

export interface SearchResults {
    galleries: Gallery[];
    photos: Photo[];
}

export function useSearch(query: string, personal: boolean = false, skipEmpty: boolean = false) {
    // SWR Key: Wenn skipEmpty true ist und die Query leer, geben wir null zurück -> SWR blockiert den Request.
    const shouldFetch = !(skipEmpty && query.trim() === '');
    const key = shouldFetch ? `/api/search?q=${encodeURIComponent(query)}${personal ? '&personal=true' : ''}` : null;

    const {data, error, isLoading} = useSWR<SearchResults>(
        key,
        fetcher,
        {
            revalidateOnFocus: false, // Verhindert ständiges Neuladen bei Tab-Wechsel
            keepPreviousData: true    // Flackerfreies Rendern beim Tippen
        }
    );

    return {
        results: data,
        isLoading,
        isError: error
    };
}
