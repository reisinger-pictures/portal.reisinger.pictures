import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

/**
 * Scalar setting values of `/api/settings/license-terms`.
 *
 * The index signature only describes the *scalar* settings this response
 * carries. `volume_pricing` is the one object-valued member and is therefore
 * NOT covered by it: it holds `preset_id` (a number) plus a tier list. Any
 * consumer of the volume preset must validate that member through
 * `parseEffectiveLicenseTerms()` from `useVolumeLicensing` instead of trusting
 * this type — the previous blind cast of this interface to a volume-pricing
 * shape is what let a numeric `preset_id` reach a `.trim()` call and crash the
 * photo page.
 */
export interface LicenseTerms {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;
    calc_outdoor_images_per_hour?: string;
    calc_flatrate_multiplier?: string;
    srp_base_price?: string;
    srp_setup_fee?: string;
    srp_privacy_fee?: string;
    srp_extra_image_fee?: string;
    pricing_strategy?: string;

    [key: string]: string | undefined;
}

export interface LicenseTermsPayload {
    calc_base_price?: string | number;
    calc_hourly_rate?: string | number;
    calc_images_per_hour?: string | number;
    calc_outdoor_images_per_hour?: string | number;
    calc_flatrate_multiplier?: string | number;
    srp_base_price?: string | number;
    srp_setup_fee?: string | number;
    srp_privacy_fee?: string | number;
    srp_extra_image_fee?: string | number;

    [key: string]: string | number | undefined;
}

/**
 * Upper bound on how long a license-terms request may keep a caller in its
 * "unresolved" state.
 *
 * SWR's `isLoading` only turns false once a fetch settles, so a request that
 * never settles — a dead proxy, a dropped connection without a RST — would
 * otherwise hold the volume-licensing UI on a spinner with no way to buy.
 * `loadingTimeout` makes the state bounded: after this budget the hook reports
 * "not loading" and the consumer falls back to the safe scope default, which
 * is the higher server-authoritative price rather than a fabricated volume
 * price. The request is still in flight and SWR will populate the real terms
 * when it arrives.
 */
export const LICENSE_TERMS_LOADING_TIMEOUT_MS = 3000;

export function useLicenseTerms() {
    const {data, isLoading, mutate} = useSWR<LicenseTerms>('/api/settings/license-terms', fetcher, {
        revalidateOnFocus: false,
        loadingTimeout: LICENSE_TERMS_LOADING_TIMEOUT_MS,
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
