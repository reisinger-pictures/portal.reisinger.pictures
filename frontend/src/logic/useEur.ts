import useSWR from 'swr';
import {apiMutate, apiUpload, fetcher} from '../api';

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

/** `GET /eur/years` — supported years and warnings for unsupported ones. */
export interface EurYearWarning {
    year: number;
    code: string;
    warning: string;
    supported: boolean;
}

export interface EurYearsResponse {
    default_year: number;
    supported: number[];
    /** Jahre mit mindestens einer Buchung im aktuellen Brand. */
    active_years: number[];
    warnings: EurYearWarning[];
}

/** `GET /eur/month` — a line's type E/A/WEB/FR. */
export interface EurMonthLine {
    type: 'E' | 'A' | 'WEB' | 'FR';
    day: number | null;
    description: string;
    amount_cents: number;
    business_amount_cents: number;
    vat_kind: string | null;
    receipt_no: string | null;
    warnings: string[];
    hints: string[];
}

export interface EurMonthCheck {
    code: string;
    ok: boolean;
    message: string;
    hint: string;
}

export interface EurMonthTotals {
    income_cents: number;
    expense_cents: number;
    warehouse_cents: number;
    vat_cents: number;
    hours_minutes: number;
}

export interface EurMonthData {
    year: number;
    month: number;
    lines: EurMonthLine[];
    totals: EurMonthTotals;
    checks: EurMonthCheck[];
    social_insurance: {amounts_cents: Record<string, number>; rates_per_10000: Record<string, number>};
    income_tax: {profit_cents: number; tax_cents: number};
    report: {status: string; closed_at: string | null};
    signature: {status: string; provider: string | null; is_test: boolean};
}

/** `GET/POST /eur/documents` — metadata; the storage path never leaves the server. */
export interface EurDocument {
    id: number;
    year: number;
    month: number | null;
    receipt_no: string | null;
    original_name: string;
    mime_type: string | null;
    size_bytes: number;
    status: string;
}

export interface EurDocumentsResponse {
    documents: EurDocument[];
}

export interface EurDocumentResponse {
    document: EurDocument;
}

/** `GET/POST /eur/hours` — time is always whole minutes. */
export interface EurHour {
    id: number;
    year: number;
    month: number;
    day: number | null;
    minutes: number;
    project: string | null;
    description: string | null;
}

export interface EurHoursResponse {
    hours: EurHour[];
    total_minutes: number;
}

/** `GET/POST /eur/warehouse` — amounts are always whole cents. */
export interface EurWarehouseEntry {
    id: number;
    year: number;
    month: number;
    day: number | null;
    description: string;
    amount_cents: number;
    receipt_no: string | null;
}

export interface EurWarehouseResponse {
    entries: EurWarehouseEntry[];
    total_cents: number;
}

/** `GET/POST /eur/applications` — status open|done. */
export interface EurApplication {
    id: number;
    year: number;
    type: string;
    subject: string;
    status: 'open' | 'done';
    note: string | null;
}

export interface EurApplicationsResponse {
    applications: EurApplication[];
}

/** `GET/POST /eur/signatures` — test providers are flagged with is_test. */
export interface EurSignature {
    id: number;
    year: number;
    month: number;
    eur_document_id: number | null;
    provider: string;
    status: string;
    signed_at: string | null;
    note: string | null;
}

export interface EurSignaturesResponse {
    signatures: EurSignature[];
}

export interface EurSignatureResponse {
    signature: EurSignature;
    is_test: boolean;
}

/** `GET /eur/analytics` — by customer/project/month; the client does not recalculate. */
export interface EurAnalyticsRow {
    name: string;
    revenue_cents: number;
    cost_cents: number;
    margin_cents: number;
}

export interface EurAnalyticsMonth {
    month: number;
    income_cents: number;
    expense_cents: number;
    profit_cents: number;
}

export interface EurAnalytics {
    by_customer: EurAnalyticsRow[];
    by_project: EurAnalyticsRow[];
    by_month: EurAnalyticsMonth[];
}

/** German month names, indexed 0..11, shared by the EÜR cockpit panels. */
export const EUR_MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
] as const;

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

export function useEurYears() {
    return useSWR<EurYearsResponse>('/api/management/eur/years', fetcher, SWR_OPTIONS);
}

export function useEurMonth(year = 2026, month = 1) {
    return useSWR<EurMonthData>(`/api/management/eur/month?year=${year}&month=${month}`, fetcher, SWR_OPTIONS);
}

export function useEurDocuments(year = 2026) {
    return useSWR<EurDocumentsResponse>(`/api/management/eur/documents?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEurHours(year = 2026, month?: number) {
    const suffix = month === undefined ? '' : `&month=${month}`;
    return useSWR<EurHoursResponse>(`/api/management/eur/hours?year=${year}${suffix}`, fetcher, SWR_OPTIONS);
}

export function useEurWarehouse(year = 2026, month?: number) {
    const suffix = month === undefined ? '' : `&month=${month}`;
    return useSWR<EurWarehouseResponse>(`/api/management/eur/warehouse?year=${year}${suffix}`, fetcher, SWR_OPTIONS);
}

export function useEurApplications(year = 2026) {
    return useSWR<EurApplicationsResponse>(`/api/management/eur/applications?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEurSignatures(year = 2026) {
    return useSWR<EurSignaturesResponse>(`/api/management/eur/signatures?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEurAnalytics(year = 2026) {
    return useSWR<EurAnalytics>(`/api/management/eur/analytics?year=${year}`, fetcher, SWR_OPTIONS);
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

export type EurIncomeTaxMode = 'total' | 'business' | 'rental' | 'forecast';

export interface EurIncomeTaxResult {
    year: number;
    profit_cents: number;
    basic_allowance_cents: number;
    taxable_cents: number;
    income_tax_cents: number;
    tax_free_threshold_cents: number;
    stages: EurTaxStage[];
    mode?: EurIncomeTaxMode;
    e1a?: string | null;
    e1b?: string | null;
    deductions_cents?: number;
}

export interface EurIncomeTaxOptions {
    year?: number;
    mode?: EurIncomeTaxMode;
    e1a?: string | null;
    e1b?: string | null;
}

export async function calculateEurIncomeTax(
    profitCents: number,
    options: EurIncomeTaxOptions = {},
): Promise<EurIncomeTaxResult> {
    const payload: Record<string, number | string | null> = {
        year: options.year ?? 2026,
        profit_cents: profitCents,
    };
    if (options.mode !== undefined) payload.mode = options.mode;
    if (options.e1a !== undefined) payload.e1a = options.e1a;
    if (options.e1b !== undefined) payload.e1b = options.e1b;

    return apiMutate<EurIncomeTaxResult>('/api/management/eur/est/calc', 'POST', payload);
}

/** Closes a month idempotently; the second call also returns 200. */
export async function closeEurMonth(payload: {year: number; month: number; note?: string}): Promise<void> {
    await apiMutate('/api/management/eur/month/close', 'POST', payload);
}

/** Uploads a receipt as multipart/form-data. The storage path stays server-side. */
export async function uploadEurDocument(payload: {
    file: File;
    year: number;
    month?: number;
    receipt_no?: string;
}): Promise<EurDocumentResponse> {
    const body = new FormData();
    body.append('file', payload.file);
    body.append('year', String(payload.year));
    if (payload.month !== undefined) body.append('month', String(payload.month));
    if (payload.receipt_no !== undefined) body.append('receipt_no', payload.receipt_no);

    return apiUpload<EurDocumentResponse>('/api/management/eur/documents', body);
}

export function eurDocumentDownloadUrl(id: number): string {
    return `/api/management/eur/documents/${id}/download`;
}

export async function deleteEurDocument(id: number): Promise<void> {
    await apiMutate(`/api/management/eur/documents/${id}`, 'DELETE');
}

export async function createEurHour(payload: {
    year: number;
    month: number;
    day?: number | null;
    minutes: number;
    project?: string | null;
    description?: string | null;
}): Promise<void> {
    await apiMutate('/api/management/eur/hours', 'POST', payload);
}

export async function createEurWarehouse(payload: {
    year: number;
    month: number;
    day?: number | null;
    description: string;
    amount_cents: number;
    receipt_no?: string | null;
}): Promise<void> {
    await apiMutate('/api/management/eur/warehouse', 'POST', payload);
}

export async function createEurApplication(payload: {
    year: number;
    type: string;
    subject: string;
    note?: string | null;
}): Promise<void> {
    await apiMutate('/api/management/eur/applications', 'POST', payload);
}

export async function createEurSignature(payload: {
    year: number;
    month: number;
    eur_document_id?: number | null;
    provider: string;
    status?: string;
    note?: string | null;
}): Promise<EurSignatureResponse> {
    return apiMutate<EurSignatureResponse>('/api/management/eur/signatures', 'POST', payload);
}

/** Ausfüllhilfe aus dem gepinnten Artefakt — kein Rechenpfad, keine zweite Regelquelle. */
export interface EurHelpText {
    kz: string;
    bezeichnung: string;
    hilfetext: string;
    portal_note: string | null;
}

export function useEurHelpTexts() {
    return useSWR<{help: EurHelpText[]}>('/api/management/eur/help-texts', fetcher, SWR_OPTIONS);
}
