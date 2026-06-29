import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

export interface LicenseTerms {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;
    // NEU: B2C Flex-Faktoren
    atr_base_price?: string;
    atr_setup_fee?: string;
    atr_privacy_fee?: string;
    atr_extra_image_fee?: string;

    [key: string]: string | undefined;
}

export interface LicenseTermsPayload {
    calc_base_price?: string | number;
    calc_hourly_rate?: string | number;
    calc_images_per_hour?: string | number;
    // NEU: B2C Flex-Faktoren Payloads
    atr_base_price?: string | number;
    atr_setup_fee?: string | number;
    atr_privacy_fee?: string | number;
    atr_extra_image_fee?: string | number;

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

/**
 * R-01 (naming/SRP): Bankverbindung & Impressum — nur Lizenztexte-unabhängige, sensible Felder
 * (IBAN, BIC, Empfänger, Firmenadresse, company_email). Authentifiziert (GET hinter auth:api).
 * Lizenztexte + Preisfaktoren liefert useLicenseTerms() (public-safe).
 */
export interface BillingDetails {
    bank_holder: string;
    bank_iban: string;
    bank_bic: string;
    company_street: string;
    company_zip: string;
    company_city: string;
    company_country: string;
    company_email: string;
}

export interface BillingDetailsPayload {
    bank_holder?: string;
    bank_iban?: string;
    bank_bic?: string;
    company_street?: string;
    company_zip?: string;
    company_city?: string;
    company_country?: string;
    company_email?: string;
}

export function useBillingDetails() {
    const {data, isLoading, mutate} = useSWR<BillingDetails>('/api/settings/billing-details', fetcher, {
        revalidateOnFocus: false
    });

    const updateBillingDetails = async (payload: BillingDetailsPayload) => {
        await apiMutate('/api/management/settings/billing-details', 'PUT', payload);
        await mutate();
    };

    return {billingDetails: data, isLoading, updateBillingDetails};
}
