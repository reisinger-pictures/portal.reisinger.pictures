import useSWR from 'swr';
import { fetcher } from '../api';

export interface LocationResult {
    id: string;
    type: 'city' | 'country';
    name: string;
    state: string | null;
    country: string | null;
    iso_country: string | null;
}

export function useLocations(query: string, type: 'city' | 'country') {
    const key = query.length >= 2 ? `/api/search/locations?q=${encodeURIComponent(query)}&type=${type}` : null;
    
    const { data, error, isLoading } = useSWR<LocationResult[]>(
        key,
        fetcher,
        { keepPreviousData: true }
    );

    return {
        locations: data || [],
        isLoading,
        isError: error
    };
}
