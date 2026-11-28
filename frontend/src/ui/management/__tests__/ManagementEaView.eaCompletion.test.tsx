import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import {
    createEaEigenbeleg,
    createEaMonth,
    createEaYear,
    saveEaSettings,
    useEaAnalytics,
    useEaIncomeTypeAnalytics,
    useEaExpenseTypeAnalytics,
    useEaPaymentReconciliation,
    useEaApplications,
    useEaDocuments,
    useEaEntries,
    useEaHelpTexts,
    useEaHours,
    useEaMonth,
    useEaReference,
    useEaSettings,
    useEaSignatures,
    useEaSummary,
    useEaWarehouse,
    useEaYears,
} from '../../../logic/useEa';
import { renderWithProviders } from '../../../test-setup';
import ManagementEaView from '../ManagementEaView';

vi.mock('../../../logic/useBrand', () => ({useBrand: vi.fn()}));
vi.mock('../../../logic/useLicenseTerms', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useLicenseTerms')>();
    return {...actual, useBillingDetails: vi.fn()};
});
vi.mock('../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useEa')>();
    return {
        ...actual,
        useEaYears: vi.fn(),
        useEaReference: vi.fn(),
        useEaSettings: vi.fn(),
        useEaSummary: vi.fn(),
        useEaEntries: vi.fn(),
        useEaMonth: vi.fn(),
        useEaDocuments: vi.fn(),
        useEaHelpTexts: vi.fn(),
        useEaHours: vi.fn(),
        useEaWarehouse: vi.fn(),
        useEaApplications: vi.fn(),
        useEaSignatures: vi.fn(),
        useEaAnalytics: vi.fn(),
        useEaIncomeTypeAnalytics: vi.fn(),
        useEaExpenseTypeAnalytics: vi.fn(),
        useEaPaymentReconciliation: vi.fn(),
        createEaEntry: vi.fn(),
        closeEaMonth: vi.fn(),
        uploadEaDocument: vi.fn(),
        deleteEaDocument: vi.fn(),
        eaDocumentDownloadUrl: vi.fn(() => '/api/management/ea/documents/1/download'),
        createEaHour: vi.fn(),
        createEaWarehouse: vi.fn(),
        createEaApplication: vi.fn(),
        createEaSignature: vi.fn(),
        fetchEaComparison: vi.fn(),
        calculateEaVat: vi.fn(),
        calculateEaTravel: vi.fn(),
        calculateEaIncomeTax: vi.fn(),
        createEaMonth: vi.fn(),
        createEaYear: vi.fn(),
        createEaEigenbeleg: vi.fn(),
        importEaEntries: vi.fn(),
        saveEaSettings: vi.fn(),
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
    vi.mocked(useEaYears).mockReturnValue(swrResult<ReturnType<typeof useEaYears>>({
        pinned_years: [2024, 2025, 2026],
        bookable_years: [2026],
        last_pinned_year: 2026,
        default_year: 2026,
        active_years: [2026],
    }));
    vi.mocked(useEaReference).mockReturnValue(swrResult<ReturnType<typeof useEaReference>>(undefined));
    vi.mocked(useEaDocuments).mockReturnValue(swrResult<ReturnType<typeof useEaDocuments>>({documents: []}));
    vi.mocked(useEaWarehouse).mockReturnValue(swrResult<ReturnType<typeof useEaWarehouse>>({entries: [], total_cents: 0}));
    vi.mocked(useEaApplications).mockReturnValue(swrResult<ReturnType<typeof useEaApplications>>({applications: []}));
    vi.mocked(useEaSignatures).mockReturnValue(swrResult<ReturnType<typeof useEaSignatures>>({signatures: []}));
    vi.mocked(useEaAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEaAnalytics>>({
        by_customer: [], by_project: [], by_month: [],
    }));
    const emptyMatrix = {types: [], month_totals: {}, total_cents: 0, booked_cents: 0, delta_cents: 0, match: true};
    vi.mocked(useEaIncomeTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEaIncomeTypeAnalytics>>({year: 2026, kind: 'income', ...emptyMatrix}));
    vi.mocked(useEaExpenseTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEaExpenseTypeAnalytics>>({year: 2026, kind: 'expense', ...emptyMatrix}));
    vi.mocked(useEaPaymentReconciliation).mockReturnValue(swrResult<ReturnType<typeof useEaPaymentReconciliation>>({year: 2026, methods: []}));
    vi.mocked(useEaSettings).mockReturnValue(swrResult<ReturnType<typeof useEaSettings>>({
        business_year: 2026,
        default_business_share: 100,
        sv_mode: 'gsvg',
        kleinunternehmer: false,
    }));
    vi.mocked(useEaSummary).mockReturnValue(swrResult<ReturnType<typeof useEaSummary>>({
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
    vi.mocked(useEaEntries).mockReturnValue(swrResult<ReturnType<typeof useEaEntries>>({
        entries: [{
            id: 1, year: 2026, month: 5, direction: 'expense', kind_type: 'Software',
            e1a_code: '9190', business_share: 100, amount_cents: 10000, business_amount_cents: 10000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
        }],
        totals: {income_cents: 0, expense_cents: 10000},
    }));
    vi.mocked(useEaMonth).mockReturnValue(swrResult<ReturnType<typeof useEaMonth>>({
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
    vi.mocked(useEaHours).mockReturnValue(swrResult<ReturnType<typeof useEaHours>>({hours: [], total_minutes: 90}));
    vi.mocked(useEaHelpTexts).mockReturnValue(swrResult<ReturnType<typeof useEaHelpTexts>>({help: []}));
}

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/steuern']}>
            <ManagementEaView/>
        </MemoryRouter>,
    );
}

describe('ManagementEaView EAR completion UI', () => {
    it('offers idempotent month and year creation with German success messages', async () => {
        mockHooks();
        vi.mocked(createEaMonth).mockResolvedValue({
            year: 2026, month: 5, created: true, report: {status: 'open', closed_at: null},
        });
        vi.mocked(createEaYear).mockResolvedValue({
            year: 2026,
            created: 12,
            months: [1, 2, 3].map((month) => ({year: 2026, month, status: 'open'})),
        });
        renderView();

        await userEvent.click(screen.getByRole('button', {name: 'Neuer Monat'}));
        expect(createEaMonth).toHaveBeenCalledWith({year: 2026, month: 5});
        expect(await screen.findByText(/Der Monat 5\/2026 wurde angelegt/)).toBeVisible();

        await userEvent.click(screen.getByRole('button', {name: 'Ganzes Jahr anlegen'}));
        expect(createEaYear).toHaveBeenCalledWith({year: 2026});
        expect(await screen.findByText(/Das Jahr 2026 ist angelegt \(12 Monate\)/)).toBeVisible();
    });

    it('shows the fixed Betriebsanteil-Hilfe bar and the month header counts and PDF link', () => {
        mockHooks();
        renderView();

        expect(screen.getByText(/Betriebsanteil-Hilfe: Geben Sie den betrieblichen Anteil in 5-%-Schritten/)).toBeVisible();
        expect(screen.getByText('0 Belege')).toBeVisible();
        expect(screen.getByText('Stunden: 1 h 30 min')).toBeVisible();
        expect(screen.getByRole('link', {name: 'PDF herunterladen'}))
            .toHaveAttribute('href', '/api/management/ea/pdf/month?year=2026&month=5');
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
        vi.mocked(createEaEigenbeleg).mockResolvedValue({
            entry: {
                id: 2, year: 2026, month: 5, direction: 'expense', kind_type: 'Eigenbeleg',
                e1a_code: null, business_share: 100, amount_cents: 1234, business_amount_cents: 1234,
                vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
            },
        });
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const day = screen.getByLabelText('Tag', {selector: 'input[name="ebDay"]'});
        await userEvent.clear(day);
        await userEvent.type(day, '5');
        await userEvent.type(screen.getByLabelText('Beschreibung'), 'Porto');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), '12.34');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(createEaEigenbeleg).toHaveBeenCalledWith({
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
        const day = screen.getByLabelText('Tag', {selector: 'input[name="ebDay"]'});
        await userEvent.clear(day);
        await userEvent.type(day, '32');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), 'abc');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(screen.getByText('Tag zwischen 1 und 31 angeben')).toBeVisible();
        expect(screen.getByText('Beschreibung angeben')).toBeVisible();
        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(screen.getByText('Bitte geben Sie Tag, Beschreibung und einen Betrag größer als null an.')).toBeVisible();
        expect(createEaEigenbeleg).not.toHaveBeenCalled();
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
        expect(createEaEigenbeleg).not.toHaveBeenCalled();
    });

    it('rejects a zero-amount Eigenbeleg (backend requires min:1)', async () => {
        mockHooks();
        vi.clearAllMocks();
        renderView();

        await userEvent.click(screen.getByRole('button', {name: '+ Eigenbeleg'}));
        const day = screen.getByLabelText('Tag', {selector: 'input[name="ebDay"]'});
        await userEvent.clear(day);
        await userEvent.type(day, '5');
        await userEvent.type(screen.getByLabelText('Beschreibung'), 'Null');
        await userEvent.type(screen.getByLabelText('Betrag (€)'), '0');
        await userEvent.selectOptions(screen.getByLabelText('Betriebsanteil (%)'), '0');
        await userEvent.click(screen.getByRole('button', {name: 'Eigenbeleg speichern'}));

        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(createEaEigenbeleg).not.toHaveBeenCalled();
    });

    it('saves EAR settings from the Einstellungen tab', async () => {
        mockHooks();
        vi.mocked(saveEaSettings).mockResolvedValue(undefined);
        renderView();

        await userEvent.click(screen.getByRole('tab', {name: 'Einstellungen'}));
        expect(screen.getByRole('heading', {name: 'EAR-Einstellungen speichern'})).toBeVisible();

        await userEvent.click(screen.getByRole('button', {name: 'Einstellungen speichern'}));

        expect(saveEaSettings).toHaveBeenCalledWith({
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
            .toHaveAttribute('href', '/api/management/ea/pdf/assets?year=2026');

        await userEvent.click(screen.getByRole('tab', {name: 'Steuer'}));
        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('9040')).toBeVisible();
    });
});
