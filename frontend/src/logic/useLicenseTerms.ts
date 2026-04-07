import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export function useLicenseTerms() {
    const { data, isLoading, mutate } = useSWR<Record<string, string>>('/api/settings/license-terms', fetcher, {
        revalidateOnFocus: false
    });

    const updateTerms = async (payload: Record<string, string | number>) => {
        await apiMutate('/api/management/settings/license-terms', 'PUT', payload);
        await mutate();
    };

    return { terms: data, isLoading, updateTerms };
}
