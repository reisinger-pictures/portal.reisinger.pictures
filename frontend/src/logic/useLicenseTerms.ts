import useSWR from 'swr';
import { fetcher } from '../api';

export function useLicenseTerms() {
    const { data, isLoading } = useSWR<Record<string, string>>('/api/settings/license-terms', fetcher, {
        revalidateOnFocus: false // Diese Texte ändern sich während der Session idR nicht
    });

    return { terms: data, isLoading };
}
