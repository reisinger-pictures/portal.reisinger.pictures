import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface LicenseTerms {
    [key: string]: string;
}

export interface LicenseTermsPayload {
    [key: string]: string | number;
}

export function useLicenseTerms() {
    const { data, isLoading, mutate } = useSWR<LicenseTerms>('/api/settings/license-terms', fetcher, {
        revalidateOnFocus: false
    });

    const updateTerms = async (payload: LicenseTermsPayload) => {
        await apiMutate('/api/management/settings/license-terms', 'PUT', payload);
        await mutate();
    };

    return { terms: data, isLoading, updateTerms };
}
