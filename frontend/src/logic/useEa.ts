import useSWR, {mutate as globalMutate} from 'swr';
import {apiMutate, apiUpload, fetcher} from '../api';

/**
 * EAR · API-Vertrag der Buchhaltung.
 *
 * Alle Geldwerte sind ganzzahlige Cent (`*_cents`), Anteile ganze Prozent in
 * 5-%-Schritten, Sätze Basispunkte (1/10000). Die Umrechnung in Euro passiert
 * erst in der Anzeige; im State bleibt jeder Betrag eine ganze Zahl.
 */
export interface EaKind {
    type: string;
    e1a: string | null;
    share_percent: number | null;
}

export interface EaTravelRate {
    country: string;
    day_cents: number;
    night_cents: number;
}

export interface EaTaxStage {
    from_cents: number;
    to_cents: number | null;
    rate_per_10000: number;
}

export interface EaReference {
    business_year: number;
    units: {money: string; time: string; share: string; rate: string};
    expense_kinds: EaKind[];
    income_kinds: EaKind[];
    vat_kinds: string[];
    vehicle_types: {name: string; category: string}[];
    payment_methods: {kind: string; opening_balance_cents: number | null}[];
    projects: string[];
    /** EUE-006: echte, brand-scope Projekt-IDs für die Buchung. */
    ea_projects?: {id: number; name: string}[];
    /** EUE-029: Verwendungszähler je Art und Richtung aus den Journalen. */
    usage_counts?: {expense: Record<string, number>; income: Record<string, number>};
    travel: {
        countries: EaTravelRate[];
        domestic: {day_cents: number; night_cents: number};
    };
    holidays: {date: string; name: string}[];
    income_tax: {
        stages: EaTaxStage[];
        tax_free_threshold_cents: number;
        basic_allowance: {limit_cents: number; rate_per_10000: number; maximum_cents: number};
    };
    social_insurance: {
        amounts_cents: Record<string, number>;
        rates_per_10000: Record<string, number>;
    };
    /** EUE-051: GWG-Grenze aus dem gepinnten Katalog (optional für ältere Caches). */
    assets?: {gwg_limit_cents: number};
}

/** One row of the monthly social-insurance overview. All amounts are cents. */
export interface EaSvMonthly {
    month: number;
    kv_cents: number;
    pv_cents: number;
    svs_cents: number;
    uv_cents: number;
}

/** A labelled "weitere Versicherungen" block with its monthly amount in cents. */
export interface EaInsuranceBlock {
    label: string;
    monthly_cents: number;
}

/** AfA card: booked business depreciation, private share and remaining book value. */
export interface EaAfaCard {
    booking_cents: number;
    private_cents: number;
    residual_cents: number;
}

/** One customer/project profitability row of the annual overview. */
export interface EaByCustomer {
    customer: string;
    project: string;
    revenue_cents: number;
    cost_cents: number;
    margin_cents: number;
}

export interface EaSvReminder {
    target_per_quarter_cents: number;
    quarters: Array<{quarter: number; booked_cents: number; done: boolean}>;
    open_quarters: number[];
    message: string | null;
}

export interface EaSummary {
    year: number;
    entry_count: number;
    asset_count: number;
    income_cents: number;
    expense_cents: number;
    afa_cents: number;
    profit_cents: number;
    /** Additive fields from the completed EAR summary contract; optional so older payloads stay renderable. */
    sv_monthly?: EaSvMonthly[];
    /** Portal #58: nicht blockierende Erinnerung an die 9225-Buchung. */
    sv_reminder?: EaSvReminder;
    insurance_blocks?: EaInsuranceBlock[];
    afa_card?: EaAfaCard;
    by_customer?: EaByCustomer[];
    open_documents?: number;
    signature_status?: string;
}

export interface EaEntry {
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
    checks?: {code: string; ok: boolean; message: string; hint: string}[];
}

export interface EaEntriesResponse {
    entries: EaEntry[];
    totals: {income_cents: number; expense_cents: number};
}

export interface EaPlanYear {
    year: number;
    method: string;
    depreciation_cents: number;
    book_value_cents: number;
    private_use_percent: number;
    private_use_cents: number;
    business_use_cents: number;
    status: string;
    /** #34: present once the year-end booking created a journal entry. */
    ea_entry_id?: number | null;
    booked_at?: string | null;
    /** D-85: directory this year row belongs to. Optional for legacy caches. */
    scope?: 'business' | 'private';
    /** D-85: set when this row was created by the idempotent year carry-over. */
    carry_over_from_year?: number | null;
    /** D-85: documentation-only voucher of a private asset's theoretical AfA. */
    eigenbeleg_no?: string | null;
    /** Whole cents of the theoretical depreciation before the usage share. */
    theoretical_depreciation_cents?: number | null;
    /** Whole percent (not basis points) of business usage for that year. */
    usage_percent?: number | null;
    /** Whole cents documented by the Eigenbeleg. */
    eigenbeleg_cents?: number | null;
    eigenbeleg_created_at?: string | null;
}

/** D-85: a private asset's Eigenbeleg; never a journal entry. */
export interface EaEigenbeleg {
    year: number;
    eigenbeleg_no: string | null;
    theoretical_depreciation_cents: number | null;
    usage_percent: number | null;
    eigenbeleg_cents: number | null;
    eigenbeleg_created_at: string | null;
}

/** D-85: receipt metadata attached to an asset master. No storage path is ever sent. */
export interface EaAssetDocument {
    id: number;
    receipt_no: string | null;
    original_name: string;
    sha256: string;
}

export interface EaAsset {
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
    years: EaPlanYear[];
    /** D-85: server-derived directory (`business` at >= 50 % business share). */
    scope?: 'business' | 'private';
    /** D-85: receipts belong to the master and are never duplicated per year. */
    documents?: EaAssetDocument[];
    /** #57: billing group; `null` means the asset is billed individually. */
    ea_asset_group_id?: number | null;
    /** #34 P5.4: private/mixed-use investment shown in its own view. */
    is_private_investment?: boolean;
    category?: string | null;
    /** #34 P5.3: a permanent switch to linear is recorded here. */
    switched_to_linear_at?: string | null;
    switch_reason?: string | null;
    /** DEG-006/007/008: Ausschlussmerkmale der degressiven AfA. */
    is_used?: boolean;
    is_immaterial?: boolean;
    immaterial_exception?: string | null;
    special_depreciation?: boolean;
    is_electric_vehicle?: boolean;
    /** EUE-053/058: nicht blockierende Hinweise aus dem gepinnten Regeltext. */
    warnings?: {code: string; text: string}[];
    /** EUE-055: automatische Entnahme-Buchung bei Anschaffung. */
    entnahme?: {done: boolean; entry_id: number | null; amount_cents: number | null};
    /** DEG-013: empfohlener Wechsel, serverseitig aus dem AfA-Plan abgeleitet. */
    switch_hint?: {
        year: number;
        linear_cents: number;
        degressive_cents: number;
        advantage_cents: number;
    } | null;
}

/**
 * #57: one group is one billing unit. Business groups (>= 50 %) produce a
 * single Entnahme, private groups a single Eigenbeleg per year. Receipts stay
 * on the individual assets.
 */
export interface EaAssetGroup {
    id: number;
    name: string;
    business_share: number;
    scope: 'business' | 'private';
    asset_ids: number[];
    assets: Array<{id: number; name: string; invoice_no: string; cost_cents: number}>;
    years: Array<{
        year: number;
        eigenbeleg_no: string | null;
        eigenbeleg_cents: number | null;
        usage_percent: number | null;
    }>;
    /** #59: receipts merged into this single billing unit. Optional for older payloads. */
    document_ids?: number[];
    /** #59: enriched receipts used to derive display names. Optional for older payloads. */
    documents?: Array<{
        id: number;
        receipt_no: string | null;
        original_name: string | null;
    }>;
}

export interface EaAssetGroupPayload {
    name: string;
    business_share: number;
}

/** #34 P5.2: AfA category suggested from the pinned reference catalogue. */
export interface EaSuggestedCategory {
    type: string;
    e1a: string | null;
    share_percent: number | null;
}

export interface EaAssetComparison {
    linear: {year: number; method: string; depreciation_cents: number; book_value_cents: number}[];
    degressive: {year: number; method: string; depreciation_cents: number; book_value_cents: number}[];
    /** #34 P5.2: first acquisition year side by side, amounts already in cents. */
    first_year: {
        linear_cents: number;
        degressive_cents: number;
        linear_business_cents: number;
        degressive_business_cents: number;
    };
    suggested_category: EaSuggestedCategory;
    switch_recommended_year: number | null;
    switch_advantage_cents: number;
}

/** #34 P5.2: a new asset is created from the confirmed category suggestion. */
export interface EaCreateAssetPayload {
    name: string;
    invoice_no: string;
    supplier: string;
    purchase_date: string;
    cost_cents: number;
    useful_life_years: number;
    business_share: number;
    method: 'linear' | 'degressiv';
    degressive_rate: number | null;
    category: string;
    is_private_investment: boolean;
}

export function createEaAsset(payload: EaCreateAssetPayload): Promise<{asset: EaAsset}> {
    return apiMutate('/api/management/ea/assets', 'POST', payload);
}

export interface EaSettings {
    business_year: number;
    default_business_share: number;
    sv_mode: string;
    kleinunternehmer: boolean;
}

/**
 * `GET /ea/years` — Regelbasis, Buchungsjahre und Jahre mit Buchungen.
 * `pinned_years` sind die Jahre mit eingepflegten Regeln (2024/2025 dienen nur
 * Vorjahresvergleichen); buchbar sind ausschließlich `bookable_years`
 * (Buchungsjahre ab 2026, heute `[2026]`). `last_pinned_year` ist das jüngste
 * eingepflegte Jahr; das Backend rechnet jedes buchbare Jahr damit, auch wenn
 * es dafür noch keine eigenen Regeln gibt. Es gibt keine Warnliste und kein
 * `supported` mehr.
 */
export interface EaYearsResponse {
    pinned_years: number[];
    /** Jahre, für die gebucht werden darf; 2024/2025 sind nur Regelbasis. */
    bookable_years: number[];
    last_pinned_year: number;
    default_year: number;
    /** Jahre mit mindestens einer Buchung im aktuellen Brand. */
    active_years: number[];
}

/** `GET /ea/month` — a line's type E/A/WEB/FR. */
export interface EaMonthLine {
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

export interface EaMonthCheck {
    code: string;
    ok: boolean;
    message: string;
    hint: string;
}

export interface EaMonthTotals {
    income_cents: number;
    expense_cents: number;
    warehouse_cents: number;
    vat_cents: number;
    hours_minutes: number;
}

/** Aggregated business amounts for one E1A/E1B code (additive contract shape). */
export interface EaE1CodeAmounts {
    income_cents: number;
    expense_cents: number;
}

/**
 * The month endpoint currently delivers grouped nets (`{E1A: {code: cents},
 * E1B: {code: cents}}`), while the additive contract describes per-code
 * income/expense objects. Both are accepted and normalised by `e1ByCodeRows`.
 */
export type EaE1ByCode = Record<string, EaE1CodeAmounts | Record<string, number>>;

/** One normalised row of the E1A/E1B per-code table. */
export interface EaE1DisplayRow {
    code: string;
    income_cents: number | null;
    expense_cents: number | null;
    total_cents: number | null;
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

/** Flattens both E1A/E1B delivery shapes into display rows without recalculating anything. */
export function e1ByCodeRows(source: EaE1ByCode | undefined): EaE1DisplayRow[] {
    if (!source) return [];
    const rows: EaE1DisplayRow[] = [];

    for (const [code, value] of Object.entries(source)) {
        if (!isRecord(value)) continue;
        if (typeof value.income_cents === 'number' || typeof value.expense_cents === 'number') {
            const income = typeof value.income_cents === 'number' ? value.income_cents : 0;
            const expense = typeof value.expense_cents === 'number' ? value.expense_cents : 0;
            rows.push({code, income_cents: income, expense_cents: expense, total_cents: null});
            continue;
        }
        for (const [subCode, amount] of Object.entries(value)) {
            if (typeof amount === 'number') {
                rows.push({code: `${code} ${subCode}`, income_cents: null, expense_cents: null, total_cents: amount});
            }
        }
    }

    return rows;
}

export interface EaMonthData {
    year: number;
    month: number;
    lines: EaMonthLine[];
    totals: EaMonthTotals;
    checks: EaMonthCheck[];
    social_insurance: {amounts_cents: Record<string, number>; rates_per_10000: Record<string, number>};
    income_tax: {profit_cents: number; tax_cents: number};
    report: {status: string; closed_at: string | null};
    signature: {status: string; provider: string | null; is_test: boolean};
    /** Keyed by E1A/E1B code (e.g. `9040`, `E1B 9460`); optional for older payloads. */
    e1_by_code?: EaE1ByCode;
    /** EUE-124: die acht USt-Kennzahlen des Monats. */
    vat?: {kennzahlen: Record<string, number | null>; zahllast_cents: number | null; empty: boolean};
    /** EUE-116: Kopfhinweis (💡 … OFF) aus `JournalRules::headerHint()`. */
    hint?: string;
}

/**
 * #59: one enriched row of the `ea_asset_documents` pivot. A position is the
 * asset created from one part of a split receipt; the pivot row carries the
 * display label and the ordinal within the receipt.
 */
export interface EaDocumentPosition {
    id: number;
    asset_id: number;
    asset_name: string;
    label: string | null;
    amount_cents: number | null;
    ordinal: number | null;
}

/** `GET/POST /ea/documents` — metadata; the storage path never leaves the server. */
export interface EaDocument {
    id: number;
    year: number;
    month: number | null;
    receipt_no: string | null;
    original_name: string;
    mime_type: string | null;
    size_bytes: number;
    status: string;
    /** SHA-256 of our original PDF — the anchor of the content binding. */
    sha256: string;
    signed_at: string | null;
    signed_sha256: string | null;
    signed_storage_path: string | null;
    signed_size_bytes: number | null;
    /** Journal bookings linked to this receipt. Always delivered by the backend. */
    linked_entry_ids: number[];
    /** #59: receipt total when known, and the split/merge distribution around it. */
    total_cents: number | null;
    allocated_cents: number;
    remaining_cents: number | null;
    /** #59: one row per independently billed asset split from this receipt. */
    positions: EaDocumentPosition[];
}

export interface EaDocumentsResponse {
    documents: EaDocument[];
}

export interface EaDocumentResponse {
    document: EaDocument;
}

/** `GET/POST /ea/documents/{id}/positions` — the same receipt with its rows. */
export interface EaDocumentPositionsResponse {
    document: {
        id: number;
        receipt_no: string | null;
        original_name: string | null;
        total_cents: number | null;
        allocated_cents: number;
        remaining_cents: number | null;
    };
    positions: EaDocumentPosition[];
}

export interface EaDocumentPositionPayload {
    name: string;
    amount_cents: number;
    business_share: number;
    useful_life_years: number;
    method?: 'linear' | 'degressive';
    label?: string | null;
    ordinal?: number | null;
}

export interface EaDocumentPositionsPayload {
    total_cents?: number | null;
    positions: EaDocumentPositionPayload[];
}

/** `GET/POST /ea/hours` — time is always whole minutes. */
export interface EaHour {
    id: number;
    year: number;
    month: number;
    day: number | null;
    minutes: number;
    project: string | null;
    description: string | null;
}

export interface EaHoursResponse {
    hours: EaHour[];
    total_minutes: number;
}

/** `GET/POST /ea/warehouse` — amounts are always whole cents. */
export interface EaWarehouseEntry {
    id: number;
    year: number;
    month: number;
    day: number | null;
    description: string;
    supplier: string | null;
    expense_kind: string | null;
    business_share: number | null;
    amount_cents: number;
    receipt_no: string | null;
}

export interface EaWarehouseResponse {
    entries: EaWarehouseEntry[];
    total_cents: number;
}

/** `GET/POST /ea/applications` — status open|done. */
export interface EaApplication {
    id: number;
    year: number;
    type: string;
    subject: string;
    status: 'open' | 'done';
    note: string | null;
}

export interface EaApplicationsResponse {
    applications: EaApplication[];
}

/** `GET/POST /ea/signatures` — test providers are flagged with is_test. */
export interface EaSignature {
    id: number;
    year: number;
    month: number;
    ea_document_id: number | null;
    provider: string;
    status: string;
    signed_at: string | null;
    note: string | null;
}

export interface EaSignaturesResponse {
    signatures: EaSignature[];
}

export interface EaSignatureResponse {
    signature: EaSignature;
    is_test: boolean;
}

/** `GET /ea/analytics` — by customer/project/month; the client does not recalculate. */
export interface EaAnalyticsRow {
    name: string;
    revenue_cents: number;
    cost_cents: number;
    margin_cents: number;
}

export interface EaAnalyticsMonth {
    month: number;
    income_cents: number;
    expense_cents: number;
    profit_cents: number;
}

export interface EaAnalytics {
    by_customer: EaAnalyticsRow[];
    by_project: EaAnalyticsRow[];
    by_month: EaAnalyticsMonth[];
}

/** EUE-119/120: Art × Monat aus `/analytics/by-income-type` und `by-expense-type`. */
export interface EaTypeMatrixRow {
    type: string;
    /** Centbetrag je Monat, Schlüssel 1–12. */
    months: Record<string, number>;
    total_cents: number;
    /** Nur Ausgaben: Herkunft A/AV/WEB/FR in Cent. */
    sources?: {A: number; AV: number; WEB: number; FR: number};
}

export interface EaTypeMatrix {
    year: number;
    kind: 'income' | 'expense';
    types: EaTypeMatrixRow[];
    month_totals: Record<string, number>;
    total_cents: number;
    booked_cents: number;
    delta_cents: number;
    match: boolean;
}

/** EUE-121: one month cell of the project × month cross-tab (whole cents/minutes). */
export interface EaProjectAnalyticsMonth {
    month: number;
    income_cents: number;
    expense_cents: number;
    hours_minutes: number;
    result_cents: number;
    /** Whole cents per *minute* of logged time (integer division), null without hours. */
    result_per_hour_cents: number | null;
}

/** EUE-121: the four total figures of a project row or of the whole year. */
export interface EaProjectAnalyticsTotals {
    income_cents: number;
    expense_cents: number;
    hours_minutes: number;
    result_cents: number;
    result_per_hour_cents: number | null;
}

/** EUE-121: one project row with twelve month cells and its totals. */
export interface EaProjectAnalyticsRow {
    project: string;
    months: EaProjectAnalyticsMonth[];
    totals: EaProjectAnalyticsTotals;
}

/** EUE-121: one calendar-month column of the cross-tab. */
export interface EaProjectAnalyticsMonthTotal {
    month: number;
    income_cents: number;
    expense_cents: number;
    hours_minutes: number;
    result_cents: number;
}

/** EUE-121: reconciliation marker (⚠/💡 markers are embedded in the texts). */
export interface EaProjectAnalyticsCheck {
    code: string;
    ok: boolean;
    message: string;
    hint: string;
}

/** EUE-121: `GET /ea/analytics/by-project`. The server never recalculates in the browser. */
export interface EaProjectAnalytics {
    year: number;
    projects: EaProjectAnalyticsRow[];
    month_totals: EaProjectAnalyticsMonthTotal[];
    totals: EaProjectAnalyticsTotals;
    /** Minutes booked without a project; reported so no hours are dropped silently. */
    undistributed_hours_minutes: number;
    /** Prior-year booked depreciation. Assets have no project relation (D-85). */
    prior_year_afa_cents: number;
    /** Booked 9225 expenses left out of project expenses outside the UV mode. */
    sv_excluded_cents: number;
    checks: EaProjectAnalyticsCheck[];
    match: boolean;
}

/** EUE-122: Zahlungsart × Monat aus `/payment-reconciliation`. */
export interface EaPaymentMethodMonth {
    month: number;
    in_cents: number;
    out_cents: number;
    closing_cents: number;
}

export interface EaPaymentMethodRow {
    method: string;
    opening_balance_cents: number | null;
    closing_balance_cents: number;
    status: string;
    months: EaPaymentMethodMonth[];
}

export interface EaPaymentReconciliation {
    year: number;
    methods: EaPaymentMethodRow[];
}

/** German month names, indexed 0..11, shared by the EAR cockpit panels. */
export const EUR_MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
] as const;

const SWR_OPTIONS = {revalidateOnFocus: false} as const;

/**
 * Revalidates every EAR SWR key after a write. The cockpit derives its month
 * list, totals and overview from several independent endpoints; a single write
 * must refresh all of them without the caller knowing each URL.
 */
export async function revalidateEa(): Promise<void> {
    // SWR only takes the revalidate-only path when fewer than three arguments
    // are passed (see `internalMutate` in swr 2.x). Passing an explicit
    // `undefined` as data writes `undefined` into every matching cache entry
    // first, which turns `isLoading` true on all panels and unmounts their
    // rows — including the confirmation of the write that triggered this call.
    await globalMutate(
        (key) => typeof key === 'string' && key.startsWith('/api/management/ea/'),
    );
}

export function useEaReference() {
    return useSWR<EaReference>('/api/management/ea/reference', fetcher, SWR_OPTIONS);
}

export function useEaSummary(year = 2026) {
    return useSWR<EaSummary>(`/api/management/ea/summary?year=${year}`, fetcher, SWR_OPTIONS);
}

/** D-91: eine Zeile der ODS-Ausfüllhilfe; alle Beträge ganze Cent, leere Werte `null`. */
export interface EaAusfuellhilfeDeviation {
    code: string;
    text_de: string;
}

export interface EaAusfuellhilfeField {
    order: number;
    sheet: string;
    cell: string;
    kz: string | null;
    label_de: string;
    value_cents: number | null;
    value_text: string | null;
    unit: string;
    source: string;
    deviations: EaAusfuellhilfeDeviation[];
}

export interface EaAusfuellhilfeSection {
    id: string;
    title_de: string;
    fields: EaAusfuellhilfeField[];
}

export interface EaAusfuellhilfe {
    year: number;
    units: {money: 'cents'; time: 'minutes'; rate: 'per-10000'};
    notice: string;
    empty_is_null: boolean;
    sections: EaAusfuellhilfeSection[];
}

/** D-91: Wertelieferant für die manuelle Übertragung in FinanzOnline. */
export function useEaAusfuellhilfe(year = 2026) {
    return useSWR<EaAusfuellhilfe>(`/api/management/ea/ausfuellhilfe?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaEntries(year = 2026) {
    return useSWR<EaEntriesResponse>(`/api/management/ea/entries?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaSettings() {
    return useSWR<EaSettings>('/api/management/ea/settings', fetcher, SWR_OPTIONS);
}

/** D-85: an optional scope queries one of the two asset directories server-side. */
export function useEaAssets(scope?: 'business' | 'private') {
    const query = scope === undefined ? '' : `?scope=${scope}`;
    return useSWR<{assets: EaAsset[]}>(`/api/management/ea/assets${query}`, fetcher, SWR_OPTIONS);
}

/** #57: all billing groups of the current brand, including their members. */
export function useEaAssetGroups() {
    return useSWR<{groups: EaAssetGroup[]}>('/api/management/ea/asset-groups', fetcher, SWR_OPTIONS);
}

export function useEaYears() {
    return useSWR<EaYearsResponse>('/api/management/ea/years', fetcher, SWR_OPTIONS);
}

export function useEaMonth(year = 2026, month = 1) {
    return useSWR<EaMonthData>(`/api/management/ea/month?year=${year}&month=${month}`, fetcher, SWR_OPTIONS);
}

export function useEaDocuments(year = 2026) {
    return useSWR<EaDocumentsResponse>(`/api/management/ea/documents?year=${year}`, fetcher, SWR_OPTIONS);
}

/** #59: the positions of one receipt, including its allocation summary. */
export function useEaDocumentPositions(documentId: number) {
    return useSWR<EaDocumentPositionsResponse>(
        `/api/management/ea/documents/${documentId}/positions`,
        fetcher,
        SWR_OPTIONS,
    );
}

export function useEaHours(year = 2026, month?: number) {
    const suffix = month === undefined ? '' : `&month=${month}`;
    return useSWR<EaHoursResponse>(`/api/management/ea/hours?year=${year}${suffix}`, fetcher, SWR_OPTIONS);
}

export function useEaWarehouse(year = 2026, month?: number) {
    const suffix = month === undefined ? '' : `&month=${month}`;
    return useSWR<EaWarehouseResponse>(`/api/management/ea/warehouse?year=${year}${suffix}`, fetcher, SWR_OPTIONS);
}

export function useEaApplications(year = 2026) {
    return useSWR<EaApplicationsResponse>(`/api/management/ea/applications?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaSignatures(year = 2026) {
    return useSWR<EaSignaturesResponse>(`/api/management/ea/signatures?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaAnalytics(year = 2026) {
    return useSWR<EaAnalytics>(`/api/management/ea/analytics?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaIncomeTypeAnalytics(year = 2026) {
    return useSWR<EaTypeMatrix>(`/api/management/ea/analytics/by-income-type?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaExpenseTypeAnalytics(year = 2026) {
    return useSWR<EaTypeMatrix>(`/api/management/ea/analytics/by-expense-type?year=${year}`, fetcher, SWR_OPTIONS);
}

export function useEaPaymentReconciliation(year = 2026) {
    return useSWR<EaPaymentReconciliation>(`/api/management/ea/payment-reconciliation?year=${year}`, fetcher, SWR_OPTIONS);
}

/** EUE-121: project × month cross-tab with income, expenses, hours and reconciliation. */
export function useEaProjectAnalytics(year = 2026) {
    return useSWR<EaProjectAnalytics>(`/api/management/ea/analytics/by-project?year=${year}`, fetcher, SWR_OPTIONS);
}

export interface EaEntryPayload {
    year: number;
    month: number;
    direction: 'income' | 'expense';
    kind_type: string;
    e1a_code?: string | null;
    business_share: number;
    amount_cents: number;
    receipt_no?: string | null;
    note?: string | null;
    /** EUE-005/006: Zahlungsart und Projekt der Buchung. */
    payment_method?: string | null;
    ea_project_id?: number | null;
    /** EUE-039/040: USt-Behandlung (IG-Erwerb / Reverse Charge) der Buchung. */
    vat_kind?: string | null;
}

export async function createEaEntry(payload: EaEntryPayload): Promise<void> {
    await apiMutate('/api/management/ea/entries', 'POST', payload);
}

/** #71: a partial refund of an automatic invoice, booked as negative income. */
export interface EaInvoiceCorrectionPayload {
    invoice_number: string;
    refund_cents: number;
    reason: string;
    year: number;
    month: number;
    day: number;
}

export async function createEaInvoiceCorrection(payload: EaInvoiceCorrectionPayload): Promise<void> {
    await apiMutate('/api/management/ea/entries/invoice-corrections', 'POST', payload);
}

export async function saveEaSettings(payload: {
    default_business_share: number;
    sv_mode: string;
    kleinunternehmer: boolean;
}): Promise<void> {
    await apiMutate('/api/management/ea/settings', 'PUT', payload);
}

/** `POST /ea/months` — idempotent creation of a single monthly report. */
export interface EaMonthCreateResult {
    year: number;
    month: number;
    created: boolean;
    report: {status: string; closed_at: string | null};
}

export async function createEaMonth(payload: {year: number; month: number}): Promise<EaMonthCreateResult> {
    return apiMutate<EaMonthCreateResult>('/api/management/ea/months', 'POST', payload);
}

/** `POST /ea/months/year` — idempotent creation of all monthly reports of a year. */
export interface EaYearCreateResult {
    year: number;
    created: number | boolean;
    months: {year: number; month: number; status: string}[];
}

export async function createEaYear(payload: {year: number}): Promise<EaYearCreateResult> {
    return apiMutate<EaYearCreateResult>('/api/management/ea/months/year', 'POST', payload);
}

/** `POST /ea/entries/import` — multipart CSV import into a single month. */
export interface EaImportResult {
    created: number;
    skipped: number;
    errors: string[];
}

export async function importEaEntries(payload: {file: File; year: number; month: number}): Promise<EaImportResult> {
    const body = new FormData();
    body.append('file', payload.file);
    body.append('year', String(payload.year));
    body.append('month', String(payload.month));

    return apiUpload<EaImportResult>('/api/management/ea/entries/import', body);
}

/** `POST /ea/eigenbelege` — creates a business receipt entry directly. */
export interface EaEigenbelegPayload {
    year: number;
    month: number;
    day: number;
    description: string;
    amount_cents: number;
    business_share: number;
}

export interface EaEigenbelegResponse {
    entry: EaEntry;
}

export async function createEaEigenbeleg(payload: EaEigenbelegPayload): Promise<EaEigenbelegResponse> {
    return apiMutate<EaEigenbelegResponse>('/api/management/ea/eigenbelege', 'POST', payload);
}

/** PDF endpoints are plain authenticated links; no fetch+blob detour needed. */
export function eaPdfMonthUrl(year: number, month: number): string {
    return `/api/management/ea/pdf/month?year=${year}&month=${month}`;
}

export function eaPdfEigenbelegUrl(
    year: number,
    month: number,
    description: string,
    amountCents: number,
): string {
    const query = new URLSearchParams({
        year: String(year),
        month: String(month),
        description,
        amount_cents: String(amountCents),
    });
    return `/api/management/ea/pdf/eigenbeleg?${query.toString()}`;
}

export function eaPdfAssetsUrl(year: number): string {
    return `/api/management/ea/pdf/assets?year=${year}`;
}

/** D-91: druckbare Ausfüllhilfe; kein ELSTER/FinanzOnline-Client. */
export function eaPdfAusfuellhilfeUrl(year: number): string {
    return `/api/management/ea/pdf/ausfuellhilfe?year=${year}`;
}

export async function fetchEaComparison(params: {
    cost_cents: number;
    useful_life_years: number;
    degressive_rate: number;
    acquisition_month: number;
    /** #34 P5.2: name drives the category suggestion from the reference catalogue. */
    name?: string;
    /** #34 P5.4: business share in 5-% steps (50–100). */
    business_share?: number;
}): Promise<EaAssetComparison> {
    const query = new URLSearchParams({
        cost_cents: String(params.cost_cents),
        useful_life_years: String(params.useful_life_years),
        degressive_rate: String(params.degressive_rate),
        acquisition_month: String(params.acquisition_month),
    });
    if (params.name) query.set('name', params.name);
    if (params.business_share !== undefined) query.set('business_share', String(params.business_share));

    return fetcher<EaAssetComparison>(`/api/management/ea/assets/compare?${query.toString()}`);
}

/**
 * #34 P5.5 / D-85: idempotent year-end action for an asset. A business asset
 * returns a journal `entry`; a private asset only returns an `eigenbeleg` and
 * never affects profit. Both result shapes are optional so callers must branch
 * on the directory rather than assume an entry exists.
 */
export interface EaBookAssetResult {
    entry?: {id: number; receipt_no: string | null; business_amount_cents: number};
    already_booked?: boolean;
    eigenbeleg?: EaEigenbeleg;
    already_created?: boolean;
}

export function bookEaAssetDepreciation(id: number, year: number): Promise<EaBookAssetResult> {
    return apiMutate<EaBookAssetResult>(`/api/management/ea/assets/${id}/book-depreciation`, 'POST', {year});
}

/** D-85: idempotent carry-over of missing year rows from the previous year. */
export interface EaCarryOverResult {
    year: number;
    created: number;
}

export function carryOverEaAssets(year: number): Promise<EaCarryOverResult> {
    return apiMutate<EaCarryOverResult>('/api/management/ea/assets/carry-over', 'POST', {year});
}

/** #57: create a billing group; business_share is a multiple of 5 in 0–100. */
export function createEaAssetGroup(payload: EaAssetGroupPayload): Promise<{group: EaAssetGroup}> {
    return apiMutate<{group: EaAssetGroup}>('/api/management/ea/asset-groups', 'POST', payload);
}

/** #57: rename a group and/or change its business share. */
export function updateEaAssetGroup(id: number, payload: EaAssetGroupPayload): Promise<{group: EaAssetGroup}> {
    return apiMutate<{group: EaAssetGroup}>(`/api/management/ea/asset-groups/${id}`, 'PUT', payload);
}

/** #57: assign an ungrouped asset to a group. */
export function assignEaAssetToGroup(id: number, assetId: number): Promise<{group: EaAssetGroup}> {
    return apiMutate<{group: EaAssetGroup}>(`/api/management/ea/asset-groups/${id}/assets`, 'POST', {asset_id: assetId});
}

/** #57: release one member back to individual bookkeeping. */
export function unassignEaAssetFromGroup(id: number, assetId: number): Promise<{group: EaAssetGroup}> {
    return apiMutate<{group: EaAssetGroup}>(`/api/management/ea/asset-groups/${id}/assets/${assetId}`, 'DELETE');
}

/** #57: delete a group; its assets are released back to individual bookkeeping. */
export function deleteEaAssetGroup(id: number): Promise<{success: true}> {
    return apiMutate<{success: true}>(`/api/management/ea/asset-groups/${id}`, 'DELETE');
}

/** D-85: attach one existing receipt to an asset master (idempotent). */
export interface EaAssetDocumentResult {
    document: EaAssetDocument;
    already_linked: boolean;
}

export function attachEaAssetDocument(id: number, documentId: number): Promise<EaAssetDocumentResult> {
    return apiMutate<EaAssetDocumentResult>(`/api/management/ea/assets/${id}/documents`, 'POST', {document_id: documentId});
}

/** D-85: detach a receipt from an asset master; the receipt itself is kept. */
export async function detachEaAssetDocument(id: number, documentId: number): Promise<void> {
    await apiMutate(`/api/management/ea/assets/${id}/documents/${documentId}`, 'DELETE');
}

/** #34 P5.3: one-time, reasoned switch from degressive to linear. */
export function switchEaAssetToLinear(id: number, year: number, reason: string): Promise<{asset: EaAsset}> {
    return apiMutate(`/api/management/ea/assets/${id}/switch-to-linear`, 'POST', {year, reason});
}

export type EaVatKz = '000' | '016' | '057' | '070' | '072' | '073' | '008' | '125' | 'zahllast';

/** One USt declaration block as delivered by the declaration service. */
export interface EaVatDeclarationBlock {
    key: string;
    title: string;
    text: string;
    title_upper?: string;
    title_cell?: string;
    year_cell?: string;
    wirtschaftsjahr?: number;
    source?: string;
    kz?: Record<string, number | null>;
    zahllast_cents?: number | null;
}

/** Both delivery shapes are rendered: two plain strings or two structured blocks. */
export type EaVatErklaerung = string[] | Record<string, EaVatDeclarationBlock>;

export interface EaVatResult {
    year: number;
    month: number;
    kz: Record<EaVatKz, number | null>;
    /** The two USt declaration blocks (Voranmeldung / Erklärung); optional for older payloads. */
    erklaerung?: EaVatErklaerung;
}

export interface EaVatPayload {
    year: number;
    month: number;
    small_business_income_cents: number | null;
    reverse_charge_cents: number | null;
    ig_bases_cents: Record<string, number | null> | null;
}

export async function calculateEaVat(payload: EaVatPayload): Promise<EaVatResult> {
    return apiMutate<EaVatResult>('/api/management/ea/vat/calc', 'POST', payload);
}

export interface EaTravelResult {
    year: number;
    kilometres: number;
    kilometre_cents: number;
    day_cents: number;
    night_cents: number;
    total_cents: number;
    business_cents: number;
    /** EUE-073: false bei privater Veranlassung (betrieblicher Anteil 0). */
    deductible?: boolean;
}

export interface EaTravelPayload {
    year: number;
    vehicle: string;
    /** EUE-067: entweder kilometres oder start_km + end_km. */
    kilometres?: number;
    start_km?: number;
    end_km?: number;
    passengers: number;
    country: string;
    /** EUE-073: 'Geschäftlich' (Standard) oder 'Privat'. */
    purpose?: 'Geschäftlich' | 'Privat';
    /** EUE-077: Pflicht beim Speichern, optional bei der reinen Berechnung. */
    receipt_no?: string;
    days: number;
    meals: number;
    with_receipts: boolean;
    business_share: number;
}

export async function calculateEaTravel(payload: EaTravelPayload): Promise<EaTravelResult> {
    return apiMutate<EaTravelResult>('/api/management/ea/travel/calc', 'POST', payload);
}

export type EaIncomeTaxMode = 'total' | 'business' | 'rental' | 'forecast' | 'gross_wage' | 'other_profits';

/** Classification of the E1A lump-sum allowances (a non-money block). */
export interface EaSteuerbilanzPauschalen {
    e40_code: string | null;
    e40_label: string | null;
    e39_code: string | null;
    e39_label: string | null;
    /** EUE-101/102: the pinned warning texts from the calculation response. */
    e40_warning?: string | null;
    e39_warning?: string | null;
}

/** EUE-103: Aufteilung der Endsteuer (U23/U27/U25/U24/U29), alles ganze Cent. */
export interface EaEstAllocation {
    total_income_cents?: number;
    tax_cents?: number | null;
    employment_tax_cents?: number | null;
    rental_tax_cents?: number | null;
    business_tax_cents?: number | null;
    due_cents?: number | null;
    prepayment_cents?: number | null;
    wage_tax_cents?: number | null;
    [key: string]: unknown;
}

/** Named figures of the tax balance sheet; keys are defined by the EAR engine. */
export interface EaSteuerbilanz {
    betriebliche_einnahmen_cents?: number;
    betriebliche_ausgaben_cents?: number;
    gewinn_cents?: number;
    steuerlicher_gewinn_cents?: number;
    afa_cents?: number;
    pauschalen?: EaSteuerbilanzPauschalen;
    [key: string]: unknown;
}

export interface EaIncomeTaxResult {
    year: number;
    profit_cents: number;
    basic_allowance_cents: number;
    taxable_cents: number;
    income_tax_cents: number;
    tax_free_threshold_cents: number;
    stages: EaTaxStage[];
    mode?: EaIncomeTaxMode;
    e1a?: string | null;
    e1b?: string | null;
    deductions_cents?: number;
    /** Additional KZ values (245, 260, U13, U26, E40, E39) from the calculation. */
    kz?: Record<string, number>;
    steuerbilanz?: EaSteuerbilanz;
    est_allocation?: EaEstAllocation;
    /** Optional fallbacks when the month endpoint is not consulted for E1A/E1B rows. */
    e1_by_code?: EaE1ByCode;
    e1a_e1b?: EaE1ByCode;
}

export interface EaIncomeTaxOptions {
    year?: number;
    mode?: EaIncomeTaxMode;
    e1a?: string | null;
    e1b?: string | null;
    /** EUE-103: Bruttolohn (KZ 245), Lohnsteuer (KZ 260) und Vorauszahlung (U26). */
    employment_income_cents?: number | null;
    wage_tax_cents?: number | null;
    prepayment_cents?: number | null;
    /** EUE-101/102 und EUE-108: E40, E39 und das Arbeitszimmer (9275). */
    arbeitsplatzpauschale_cents?: number | null;
    ergonomisches_mobiliar_cents?: number | null;
    arbeitszimmer_cents?: number | null;
    /** EUE-104/106: Prognose-Flag und die Prognosewerte. */
    prognose?: boolean;
    business_forecast_cents?: number | null;
    rental_forecast_cents?: number | null;
}

export async function calculateEaIncomeTax(
    profitCents: number,
    options: EaIncomeTaxOptions = {},
): Promise<EaIncomeTaxResult> {
    const payload: Record<string, number | string | boolean | null> = {
        year: options.year ?? 2026,
        profit_cents: profitCents,
    };
    if (options.mode !== undefined) payload.mode = options.mode;
    if (options.e1a !== undefined) payload.e1a = options.e1a;
    if (options.e1b !== undefined) payload.e1b = options.e1b;
    if (options.employment_income_cents !== undefined) payload.employment_income_cents = options.employment_income_cents;
    if (options.wage_tax_cents !== undefined) payload.wage_tax_cents = options.wage_tax_cents;
    if (options.prepayment_cents !== undefined) payload.prepayment_cents = options.prepayment_cents;
    if (options.arbeitsplatzpauschale_cents !== undefined) {
        payload.arbeitsplatzpauschale_cents = options.arbeitsplatzpauschale_cents;
    }
    if (options.ergonomisches_mobiliar_cents !== undefined) {
        payload.ergonomisches_mobiliar_cents = options.ergonomisches_mobiliar_cents;
    }
    if (options.arbeitszimmer_cents !== undefined) payload.arbeitszimmer_cents = options.arbeitszimmer_cents;
    if (options.prognose !== undefined) payload.prognose = options.prognose;
    if (options.business_forecast_cents !== undefined) payload.business_forecast_cents = options.business_forecast_cents;
    if (options.rental_forecast_cents !== undefined) payload.rental_forecast_cents = options.rental_forecast_cents;

    return apiMutate<EaIncomeTaxResult>('/api/management/ea/est/calc', 'POST', payload);
}

/** Closes a month idempotently; the second call also returns 200. */
export async function closeEaMonth(payload: {year: number; month: number; note?: string}): Promise<void> {
    await apiMutate('/api/management/ea/month/close', 'POST', payload);
}

/** Uploads a receipt as multipart/form-data. The storage path stays server-side. */
export async function uploadEaDocument(payload: {
    file: File;
    year: number;
    month?: number;
    receipt_no?: string;
}): Promise<EaDocumentResponse> {
    const body = new FormData();
    body.append('file', payload.file);
    body.append('year', String(payload.year));
    if (payload.month !== undefined) body.append('month', String(payload.month));
    if (payload.receipt_no !== undefined) body.append('receipt_no', payload.receipt_no);

    return apiUpload<EaDocumentResponse>('/api/management/ea/documents', body);
}

export function eaDocumentDownloadUrl(id: number): string {
    return `/api/management/ea/documents/${id}/download`;
}

/** Kinds of unsigned correction PDF the accounting engine can produce. */
export type EaCorrectionKind = 'monthly' | 'eigenbeleg' | 'assets';

export interface EaCorrectionPayload {
    kind: EaCorrectionKind;
    year: number;
    /** Relevant for `monthly` and `eigenbeleg`; ignored for `assets`. */
    month?: number;
}

/** `POST /ea/documents/corrections` — creates an unsigned PDF for external signing. */
export function createEaCorrectionDocument(payload: EaCorrectionPayload): Promise<EaDocumentResponse> {
    return apiMutate<EaDocumentResponse>('/api/management/ea/documents/corrections', 'POST', payload);
}

/**
 * `POST /ea/documents/{id}/signed` — multipart upload of the externally
 * signed PDF. On 422 the parsed JSON body carries `error` and `action` on the
 * thrown `ApiError.info`; the UI reads those through a narrow type guard.
 */
export function uploadSignedEaDocument(id: number, file: File): Promise<EaDocumentResponse> {
    const body = new FormData();
    body.append('file', file);
    return apiUpload<EaDocumentResponse>(`/api/management/ea/documents/${id}/signed`, body);
}

/** D-82: beratende OCR-Hinweise aus einem Belegbild (nie eine Buchung). */
export interface EaOcrHints {
    supported: boolean;
    reason?: string;
    text?: string;
    receipt_no?: string | null;
    date?: string | null;
    amount_cents?: number | null;
}

export function ocrEaDocument(id: number): Promise<{ocr: EaOcrHints}> {
    return apiMutate<{ocr: EaOcrHints}>(`/api/management/ea/documents/${id}/ocr`, 'POST');
}

/** Authenticated link to the signed PDF bytes. */
export function eaDocumentSignedDownloadUrl(id: number): string {
    return `/api/management/ea/documents/${id}/signed/download`;
}

export async function deleteEaDocument(id: number): Promise<void> {
    await apiMutate(`/api/management/ea/documents/${id}`, 'DELETE');
}

/**
 * #59 SPLIT: create one independently billed asset per position from a single
 * receipt. A 422 signals over-allocation or an existing split; the caller owns
 * the user-facing reaction.
 */
export async function createEaDocumentPositions(
    documentId: number,
    payload: EaDocumentPositionsPayload,
): Promise<{ document: EaDocumentPositionsResponse['document'] }> {
    const result = await apiMutate<{ document: EaDocumentPositionsResponse['document'] }>(
        `/api/management/ea/documents/${documentId}/positions`,
        'POST',
        payload,
    );
    await revalidateEa();
    return result;
}

/** #59: remove only the pivot link; the asset itself is kept. */
export async function deleteEaDocumentPosition(positionId: number): Promise<void> {
    await apiMutate(`/api/management/ea/documents/positions/${positionId}`, 'DELETE');
    await revalidateEa();
}

/** #59 MERGE: put a receipt into one existing billing group; `null` detaches it. */
export async function setEaDocumentGroup(
    documentId: number,
    groupId: number | null,
): Promise<{group: EaAssetGroup}> {
    const result = await apiMutate<{group: EaAssetGroup}>(
        `/api/management/ea/documents/${documentId}/group`,
        'PUT',
        {group_id: groupId},
    );
    await revalidateEa();
    return result;
}

export async function createEaHour(payload: {
    year: number;
    month: number;
    day?: number | null;
    minutes: number;
    project?: string | null;
    description?: string | null;
}): Promise<void> {
    await apiMutate('/api/management/ea/hours', 'POST', payload);
}

/** D-83b: business_share is server-derived from the pinned expense-kind mapping. */
export async function createEaWarehouse(payload: {
    year: number;
    month: number;
    day: number;
    description: string;
    supplier: string;
    expense_kind?: string | null;
    amount_cents: number;
    receipt_no: string;
}): Promise<void> {
    await apiMutate('/api/management/ea/warehouse', 'POST', payload);
}

export async function createEaApplication(payload: {
    year: number;
    type: string;
    subject: string;
    note?: string | null;
}): Promise<void> {
    await apiMutate('/api/management/ea/applications', 'POST', payload);
}

export async function createEaSignature(payload: {
    year: number;
    month: number;
    ea_document_id?: number | null;
    provider: string;
    status?: string;
    note?: string | null;
}): Promise<EaSignatureResponse> {
    return apiMutate<EaSignatureResponse>('/api/management/ea/signatures', 'POST', payload);
}

/** Ausfüllhilfe aus dem gepinnten Artefakt — kein Rechenpfad, keine zweite Regelquelle. */
export interface EaHelpText {
    kz: string;
    bezeichnung: string;
    hilfetext: string;
    portal_note: string | null;
}

export function useEaHelpTexts() {
    return useSWR<{help: EaHelpText[]}>('/api/management/ea/help-texts', fetcher, SWR_OPTIONS);
}
