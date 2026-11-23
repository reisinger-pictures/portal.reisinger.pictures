import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import {
    createEurEigenbeleg,
    createEurMonth,
    createEurYear,
    saveEurSettings,
    useEurAnalytics,
    useEurIncomeTypeAnalytics,
    useEurExpenseTypeAnalytics,
    useEurPaymentReconciliation,
    useEurApplications,
    useEurDocuments,
    useEurEntries,
    useEurHelpTexts,
    useEurHours,
    useEurMonth,
    useEurReference,
    useEurSettings,
    useEurSignatures,
    useEurSummary,
    useEurWarehouse,
    useEurYears,
} from '../../../logic/useEur';
import { renderWithProviders } from '../../../test-setup';
import ManagementEurView from '../ManagementEurView';

vi.mock('../../../logic/useBrand', () => ({useBrand: vi.fn()}));
vi.mock('../../../logic/useLicenseTerms', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useLicenseTerms')>();
    return {...actual, useBillingDetails: vi.fn()};
});
vi.mock('../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useEur')>();
    return {
        ...actual,
        useEurYears: vi.fn(),
        useEurReference: vi.fn(),
        useEurSettings: vi.fn(),
        useEurSummary: vi.fn(),
        useEurEntries: vi.fn(),
        useEurMonth: vi.fn(),
        useEurDocuments: vi.fn(),
        useEurHelpTexts: vi.fn(),
        useEurHours: vi.fn(),
        useEurWarehouse: vi.fn(),
        useEurApplications: vi.fn(),
        useEurSignatures: vi.fn(),
        useEurAnalytics: vi.fn(),
        useEurIncomeTypeAnalytics: vi.fn(),
        useEurExpenseTypeAnalytics: vi.fn(),
        useEurPaymentReconciliation: vi.fn(),
        createEurEntry: vi.fn(),
        closeEurMonth: vi.fn(),
        uploadEurDocument: vi.fn(),
        deleteEurDocument: vi.fn(),
        eurDocumentDownloadUrl: vi.fn(() => '/api/management/eur/documents/1/download'),
        createEurHour: vi.fn(),
        createEurWarehouse: vi.fn(),
        createEurApplication: vi.fn(),
        createEurSignature: vi.fn(),
        fetchEurComparison: vi.fn(),
        calculateEurVat: vi.fn(),
        calculateEurTravel: vi.fn(),
        calculateEurIncomeTax: vi.fn(),
        createEurMonth: vi.fn(),
        createEurYear: vi.fn(),
        createEurEigenbeleg: vi.fn(),
        importEurEntries: vi.fn(),
        saveEurSettings: vi.fn(),
    };
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function mockHooks() {
    vi.mocked(useBrand).mockReturnValue({
        brand: 'test-brand',
        config: null,
        logoSrc: '/brands/test-brand/android-chrome-192x192.png',
        svgUrl: '/brands/test-brand/safari-pinned-tab.svg',
        portalName: 'Test-Portal',
        impressumUrl: null,
        features: {},
        theme: {light: 'rp-light', dark: 'rp-dark'},
        primaryColor: '#1E5631',
        secondaryColor: '#A4B494',
    });
    vi.mocked(useBillingDetails).mockReturnValue({
        billingDetails: undefined,
        isLoading: false,
        updateBillingDetails: vi.fn(),
    });
    vi.mocked(useEurYears).mockReturnValue(swrResult<ReturnType<typeof useEurYears>>({
        default_year: 2026,
        supported: [2024, 2025, 2026],
        active_years: [2026],
        warnings: [],
    }));
    vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>(undefined));
    vi.mocked(useEurDocuments).mockReturnValue(swrResult<ReturnType<typeof useEurDocuments>>({documents: []}));
    vi.mocked(useEurWarehouse).mockReturnValue(swrResult<ReturnType<typeof useEurWarehouse>>({entries: [], total_cents: 0}));
    vi.mocked(useEurApplications).mockReturnValue(swrResult<ReturnType<typeof useEurApplications>>({applications: []}));
    vi.mocked(useEurSignatures).mockReturnValue(swrResult<ReturnType<typeof useEurSignatures>>({signatures: []}));
    vi.mocked(useEurAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurAnalytics>>({
        by_customer: [], by_project: [], by_month: [],
    }));
    const emptyMatrix = {types: [], month_totals: {}, total_cents: 0, booked_cents: 0, delta_cents: 0, match: true};
    vi.mocked(useEurIncomeTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurIncomeTypeAnalytics>>({year: 2026, kind: 'income', ...emptyMatrix}));
    vi.mocked(useEurExpenseTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurExpenseTypeAnalytics>>({year: 2026, kind: 'expense', ...emptyMatrix}));
    vi.mocked(useEurPaymentReconciliation).mockReturnValue(swrResult<ReturnType<typeof useEurPaymentReconciliation>>({year: 2026, methods: []}));
    vi.mocked(useEurSettings).mockReturnValue(swrResult<ReturnType<typeof useEurSettings>>({
        business_year: 2026,
        default_business_share: 100,
        sv_mode: 'gsvg',
        kleinunternehmer: false,
    }));
    vi.mocked(useEurSummary).mockReturnValue(swrResult<ReturnType<typeof useEurSummary>>({
        year: 2026,
        entry_count: 1,
        asset_count: 0,
        income_cents: 0,
        expense_cents: 10000,
        afa_cents: 0,
        profit_cents: 0,
        open_documents: 3,
        signature_status: 'none',
    }));
    vi.mocked(useEurEntries).mockReturnValue(swrResult<ReturnType<typeof useEurEntries>>({
        entries: [{
            id: 1, year: 2026, month: 5, direction: 'expense', kind_type: 'Software',
            e1a_code: '9190', business_share: 100, amount_cents: 10000, business_amount_cents: 10000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
        }],
        totals: {income_cents: 0, expense_cents: 10000},
    }));
    vi.mocked(useEurMonth).mockReturnValue(swrResult<ReturnType<typeof useEurMonth>>({
        year: 2026,
        month: 5,
        lines: [],
        totals: {income_cents: 0, expense_cents: 0, warehouse_cents: 0, vat_cents: 0, hours_minutes: 0},
        checks: [],
        social_insurance: {amounts_cents: {}, rates_per_10000: {}},
        income_tax: {profit_cents: 0, tax_cents: 0},
        report: {status: 'open', closed_at: null},
        signature: {status: 'none', provider: null, is_test: false},
        e1_by_code: {'9040': {income_cents: 10000, expense_cents: 0}},
    }));
    vi.mocked(useEurHours).mockReturnValue(swrResult<ReturnType<typeof useEurHours>>({hours: [], total_minutes: 90}));
    vi.mocked(useEurHelpTexts).mockReturnValue(swrResult<ReturnType<typeof useEurHelpTexts>>({help: []}));
}

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/steuern']}>
            <ManagementEurView/>
        </MemoryRouter>,
    );
}

describe('ManagementEurView EAR completion UI', () => {
    it('offers idempotent month and year creation with German success messages', async () => {
        mockHooks();
        vi.mocked(createEurMonth).mockResolvedValue({
            year: 2026, month: 5, created: true, report: {status: 'open', closed_at: null},
        });
        vi.mocked(createEurYear).mockResolvedValue({
            year: 2026,
            created: 12,
            months: [1, 2, 3].map((month) => ({year: 2026, month, status: 'open'})),
        });
        renderView();

        await userEvent.click(screen.getByRole('button', {name: 'Neuer Monat'}));
        expect(createEurMonth).toHaveBeenCalledWith({year: 2026, month: 5});
        expect(await screen.findByText(/Der Monat 5\/2026 wurde angelegt/)).toBeVisible();

        await userEvent.click(screen.getByRole('button', {name: 'Ganzes Jahr anlegen'}));
        expect(createEurYear).toHaveBeenCalledWith({year: 2026});
        expect(await screen.findByText(/Das Jahr 2026 ist angelegt \(12 Monate\)/)).toBeVisible();
    });

    it('shows the fixed Betriebsanteil-Hilfe bar and the month header counts and PDF link', () => {
        mockHooks();
        renderView();

        expect(screen.getByText(/Betriebsanteil-Hilfe: Geben Sie den betrieblichen Anteil in 5-%-Schritten/)).toBeVisible();
        expect(screen.getByText('0 Belege')).toBeVisible();
        expect(screen.getByText('Stunden: 1 h 30 min')).toBeVisible();
        expect(screen.getByRole('link', {name: 'PDF herunterladen'}))
            .toHaveAttribute('href', '/api/management/eur/pdf/month?year=2026&month=5');
    });

    it('shows the business-vs-private bar and the open-documents / unsigned footer', () => {
        mockHooks();
        renderView();

        expect(screen.getByText('Betrieblich', {selector: 'span'})).toBeVisible();
        expect(screen.getByText('Privat', {selector: 'span'})).toBeVisible();
        expect(screen.getByText(/Betriebliche Einnahmen/)).toBeVisible();
        expect(screen.getByText('Offene Belege: 3')).toBeVisible();
        expect(screen.getByText('Monat nicht signiert')).toBeVisible();
    });

    it('creates an Eigenbeleg from the month header action', async () => {
        mockHooks();
        vi.mocked(createEurEigenbeleg).mockResolvedValue({
            entry: {
                id: 2, year: 2026, month: 5, direction: 'expense', kind_type: 'Eigenbeleg',
                e1a_code: null, business_share: 100, amount_cents: 1234, business_amount_cents: 1234,
                vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
            },
        });
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const day = screen.getByLabelText('Tag');
        await userEvent.clear(day);
        await userEvent.type(day, '5');
        await userEvent.type(screen.getByLabelText('Beschreibung'), 'Porto');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), '12.34');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(createEurEigenbeleg).toHaveBeenCalledWith({
            year: 2026,
            month: 5,
            day: 5,
            description: 'Porto',
            amount_cents: 1234,
            business_share: 100,
        });
        expect(await screen.findByText('Der Eigenbeleg wurde erfasst.')).toBeVisible();
        expect(screen.getByRole('link', {name: 'Eigenbeleg-PDF herunterladen'})).toBeVisible();
    });

    it('rejects an invalid Eigenbeleg with inline German errors and no API call', async () => {
        mockHooks();
        vi.clearAllMocks();
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const day = screen.getByLabelText('Tag');
        await userEvent.clear(day);
        await userEvent.type(day, '32');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), 'abc');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(screen.getByText('Tag zwischen 1 und 31 angeben')).toBeVisible();
        expect(screen.getByText('Beschreibung angeben')).toBeVisible();
        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(screen.getByText('Bitte geben Sie Tag, Beschreibung und einen Betrag größer als null an.')).toBeVisible();
        expect(createEurEigenbeleg).not.toHaveBeenCalled();
    });

    it('rejects a Betriebsanteil outside the 5-% steps', async () => {
        mockHooks();
        vi.clearAllMocks();
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const share = screen.getByLabelText('Betriebsanteil (%)');
        const option = document.createElement('option');
        option.value = '7';
        option.textContent = '7 %';
        share.append(option);
        await userEvent.selectOptions(share, '7');
        await userEvent.type(screen.getByLabelText('Beschreibung'), 'Porto');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), '5');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(screen.getByText('Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben')).toBeVisible();
        expect(createEurEigenbeleg).not.toHaveBeenCalled();
    });

    it('rejects a zero-amount Eigenbeleg (backend requires min:1)', async () => {
        mockHooks();
        vi.clearAllMocks();
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const day = screen.getByLabelText('Tag');
        await userEvent.clear(day);
        await userEvent.type(day, '5');
        await userEvent.type(screen.getByLabelText('Beschreibung'), 'Null');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), '0');
        await userEvent.selectOptions(screen.getByLabelText('Betriebsanteil (%)'), '0');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(createEurEigenbeleg).not.toHaveBeenCalled();
    });

    it('saves EAR settings from the Einstellungen tab', async () => {
        mockHooks();
        vi.mocked(saveEurSettings).mockResolvedValue(undefined);
        renderView();

        await userEvent.click(screen.getByRole('tab', {name: 'Einstellungen'}));
        expect(screen.getByRole('heading', {name: 'EAR-Einstellungen speichern'})).toBeVisible();

        await userEvent.click(screen.getByRole('button', {name: 'Einstellungen speichern'}));

        expect(saveEurSettings).toHaveBeenCalledWith({
            default_business_share: 100,
            sv_mode: 'gsvg',
            kleinunternehmer: false,
        });
        expect(await screen.findByText('Die EAR-Einstellungen wurden gespeichert.')).toBeVisible();
    });

    it('links the asset list PDF and passes month E1A/E1B rows to the tax panel', async () => {
        mockHooks();
        renderView();

        await userEvent.click(screen.getByRole('tab', {name: 'Anlagen'}));
        expect(screen.getByRole('link', {name: 'Anlagenverzeichnis als PDF'}))
            .toHaveAttribute('href', '/api/management/eur/pdf/assets?year=2026');

        await userEvent.click(screen.getByRole('tab', {name: 'Steuer'}));
        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('9040')).toBeVisible();
    });
});
