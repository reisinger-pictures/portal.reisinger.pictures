import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

export interface LicenseTerms {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;

    [key: string]: string | undefined;
}

export interface LicenseTermsPayload {
    calc_base_price?: string | number;
    calc_hourly_rate?: string | number;
    calc_images_per_hour?: string | number;

    [key: string]: string | number | undefined;
}

export function useLicenseTerms() {
    const {data, isLoading, mutate} = useSWR<LicenseTerms>('/api/settings/license-terms', fetcher, {
        revalidateOnFocus: false
    });

    const updateTerms = async (payload: LicenseTermsPayload) => {
        await apiMutate('/api/management/settings/license-terms', 'PUT', payload);
        await mutate();
    };

    return {terms: data, isLoading, updateTerms};
}
