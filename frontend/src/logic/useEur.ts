import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

/**
 * EÜR · API-Vertrag der Buchhaltung.
 *
 * Alle Geldwerte sind ganzzahlige Cent (`*_cents`), Anteile ganze Prozent in
 * 5-%-Schritten, Sätze Basispunkte (1/10000). Die Umrechnung in Euro passiert
 * erst in der Anzeige; im State bleibt jeder Betrag eine ganze Zahl.
 */
export interface EurKind {
    type: string;
    e1a: string | null;
    share_percent: number | null;
}

export interface EurTravelRate {
    country: string;
    day_cents: number;
    night_cents: number;
}

export interface EurTaxStage {
    from_cents: number;
    to_cents: number | null;
    rate_per_10000: number;
}

export interface EurReference {
    business_year: number;
    units: {money: string; time: string; share: string; rate: string};
    expense_kinds: EurKind[];
    income_kinds: EurKind[];
    vat_kinds: string[];
    vehicle_types: {name: string; category: string}[];
    payment_methods: {kind: string; opening_balance_cents: number | null}[];
    projects: string[];
    travel: {
        countries: EurTravelRate[];
        domestic: {day_cents: number; night_cents: number};
    };
    holidays: {date: string; name: string}[];
    income_tax: {
        stages: EurTaxStage[];
        tax_free_threshold_cents: number;
        basic_allowance: {limit_cents: number; rate_per_10000: number; maximum_cents: number};
    };
    social_insurance: {
        amounts_cents: Record<string, number>;
        rates_per_10000: Record<string, number>;
    };
}

export interface EurSummary {
    year: number;
    entry_count: number;
    asset_count: number;
    income_cents: number;
    expense_cents: number;
    afa_cents: number;
    profit_cents: number;
}

export interface EurEntry {
    id: number;
    year: number;
    month: number;
    direction: 'income' | 'expense';
    kind_type: string;
    e1a_code: string | null;
    business_share: number;
    amount_cents: number;
    business_amount_cents: number;
    vat_kind: string | null;
    payment_method: string | null;
    project: string | null;
    customer: string | null;
    receipt_no: string | null;
    note: string | null;
}

export interface EurEntriesResponse {
    entries: EurEntry[];
    totals: {income_cents: number; expense_cents: number};
}

export interface EurPlanYear {
    year: number;
    method: string;
    depreciation_cents: number;
    book_value_cents: number;
    private_use_percent: number;
    private_use_cents: number;
    business_use_cents: number;
    status: string;
}

export interface EurAsset {
    id: number;
    name: string;
    invoice_no: string;
    supplier: string;
    purchase_date: string;
    cost_cents: number;
    useful_life_years: number;
    business_share: number;
    method: string;
    degressive_rate: number | null;
    status: string;
    years: EurPlanYear[];
}

export interface EurAssetComparison {
    linear: {year: number; method: string; depreciation_cents: number; book_value_cents: number}[];
    degressive: {year: number; method: string; depreciation_cents: number; book_value_cents: number}[];
    switch_recommended_year: number | null;
    switch_advantage_cents: number;
}

export interface EurSettings {
    business_year: number;
    default_business_share: number;
    sv_mode: string;
    kleinunternehmer: boolean;
}

const SWR_OPTIONS = {revalidateOnFocus: false} as const;

export function useEurReference() {
    return useSWR<EurReference>('/api/management/eur/reference', fetcher, SWR_OPTIONS);
}

export function useEurSummary(year = 2026) {
    return useSWR<EurSummary>(`/api/management/eur/summary?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEurEntries(year = 2026) {
    return useSWR<EurEntriesResponse>(`/api/management/eur/entries?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEurSettings() {
    return useSWR<EurSettings>('/api/management/eur/settings', fetcher, SWR_OPTIONS);
}

export function useEurAssets() {
    return useSWR<{assets: EurAsset[]}>('/api/management/eur/assets', fetcher, SWR_OPTIONS);
}

export interface EurEntryPayload {
    year: number;
    month: number;
    direction: 'income' | 'expense';
    kind_type: string;
    e1a_code?: string | null;
    business_share: number;
    amount_cents: number;
    receipt_no?: string | null;
    note?: string | null;
}

export async function createEurEntry(payload: EurEntryPayload): Promise<void> {
    await apiMutate('/api/management/eur/entries', 'POST', payload);
}

export async function saveEurSettings(payload: {
    default_business_share: number;
    sv_mode: string;
    kleinunternehmer: boolean;
}): Promise<void> {
    await apiMutate('/api/management/eur/settings', 'PUT', payload);
}

export async function fetchEurComparison(params: {
    cost_cents: number;
    useful_life_years: number;
    degressive_rate: number;
    acquisition_month: number;
}): Promise<EurAssetComparison> {
    const query = new URLSearchParams({
        cost_cents: String(params.cost_cents),
        useful_life_years: String(params.useful_life_years),
        degressive_rate: String(params.degressive_rate),
        acquisition_month: String(params.acquisition_month),
    });

    return fetcher<EurAssetComparison>(`/api/management/eur/assets/compare?${query.toString()}`);
}

export type EurVatKz = '000' | '016' | '057' | '070' | '072' | '073' | '008' | '125' | 'zahllast';

export interface EurVatResult {
    year: number;
    month: number;
    kz: Record<EurVatKz, number | null>;
}

export interface EurVatPayload {
    year: number;
    month: number;
    small_business_income_cents: number | null;
    reverse_charge_cents: number | null;
    ig_bases_cents: Record<string, number | null> | null;
}

export async function calculateEurVat(payload: EurVatPayload): Promise<EurVatResult> {
    return apiMutate<EurVatResult>('/api/management/eur/vat/calc', 'POST', payload);
}

export interface EurTravelResult {
    year: number;
    kilometres: number;
    kilometre_cents: number;
    day_cents: number;
    night_cents: number;
    total_cents: number;
    business_cents: number;
}

export interface EurTravelPayload {
    year: number;
    vehicle: string;
    kilometres: number;
    passengers: number;
    country: string;
    days: number;
    meals: number;
    with_receipts: boolean;
    business_share: number;
}

export async function calculateEurTravel(payload: EurTravelPayload): Promise<EurTravelResult> {
    return apiMutate<EurTravelResult>('/api/management/eur/travel/calc', 'POST', payload);
}

export interface EurIncomeTaxResult {
    year: number;
    profit_cents: number;
    basic_allowance_cents: number;
    taxable_cents: number;
    income_tax_cents: number;
    tax_free_threshold_cents: number;
    stages: EurTaxStage[];
}

export async function calculateEurIncomeTax(profitCents: number): Promise<EurIncomeTaxResult> {
    return apiMutate<EurIncomeTaxResult>('/api/management/eur/est/calc', 'POST', {
        year: 2026,
        profit_cents: profitCents,
    });
}
