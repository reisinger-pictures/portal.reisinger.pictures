import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import {
    createEurEntry,
    useEurAnalytics,
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

vi.mock('../../../logic/useBrand', () => ({
    useBrand: vi.fn(),
}));

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
    };
});

const billingDetails = {
    bank_holder: 'Musterfoto GmbH',
    bank_iban: 'AT11 2222 3333 4444 5555',
    bank_bic: '',
    company_street: 'Musterstraße 1',
    company_zip: '4020',
    company_city: 'Linz',
    company_country: 'Österreich',
    company_email: 'office@example.at',
    steuernummer: '12/345/67890',
    finanzamt: 'Finanzamt Linz',
    uid: 'ATU12345678',
    rechtsform: 'GmbH',
    branchenkennzahl: '742',
};

const reference = {
    business_year: 2026,
    units: {money: 'cents', time: 'minutes', share: 'percent', rate: 'per-10000'},
    expense_kinds: [
        {type: 'Software', e1a: '9190', share_percent: null},
        {type: 'Geschäftsessen', e1a: '9200', share_percent: 50},
    ],
    income_kinds: [
        {type: 'Dienstleistung', e1a: '9040', share_percent: null},
    ],
    vat_kinds: ['Reverse Charge 20 %'],
    vehicle_types: [{name: 'Privatauto', category: 'PKW oder Kombi'}],
    payment_methods: [{kind: 'Bankkonto', opening_balance_cents: null}],
    projects: ['Hauptprojekt'],
    travel: {
        countries: [{country: 'Österreich', day_cents: 3000, night_cents: 1700}],
        domestic: {day_cents: 3000, night_cents: 1700},
    },
    holidays: [{date: '2026-01-01', name: 'Neujahr'}],
    income_tax: {
        stages: [
            {from_cents: 1353900, to_cents: 2199200, rate_per_10000: 2000},
        ],
        tax_free_threshold_cents: 1353900,
        basic_allowance: {limit_cents: 3300000, rate_per_10000: 1500, maximum_cents: 495000},
    },
    social_insurance: {
        amounts_cents: {max_bgl: 808500, kv_min: 3747},
        rates_per_10000: {kv_satz: 680},
    },
};

const summary = {
    year: 2026,
    entry_count: 2,
    asset_count: 1,
    income_cents: 100000,
    expense_cents: 10000,
    afa_cents: 5000,
    profit_cents: 85000,
};

const monthData = {
    year: 2026,
    month: 5,
    lines: [{
        type: 'A', day: 5, description: 'Software', amount_cents: 10000,
        business_amount_cents: 10000, vat_kind: null, receipt_no: null,
        warnings: [], hints: ['Prüfen Sie die Belegnummer.'],
    }],
    totals: {income_cents: 0, expense_cents: 10000, warehouse_cents: 0, vat_cents: 0, hours_minutes: 90},
    checks: [{
        code: 'BE-ART', ok: false, message: 'Beleg fehlt', hint: 'Ergänzen Sie den Beleg im Tab Belege.',
    }],
    social_insurance: {amounts_cents: {max_bgl: 808500}, rates_per_10000: {kv_satz: 680}},
    income_tax: {profit_cents: 85000, tax_cents: 0},
    report: {status: 'open', closed_at: null},
    signature: {status: 'none', provider: null, is_test: false},
};

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function mockHooks(overrides: {billing?: typeof billingDetails | undefined; summaryData?: typeof summary; noSummary?: boolean; noBilling?: boolean; activeYears?: number[]} = {}) {
    vi.mocked(useBrand).mockReturnValue({
        brand: 'test-brand',
        config: null,
        logoSrc: '/brands/test-brand/android-chrome-192x192.png',
        svgUrl: '/brands/test-brand/safari-pinned-tab.svg',
        portalName: 'Test-Portal',
        impressumUrl: null,
        features: {},
        theme: { light: 'rp-light', dark: 'rp-dark' },
        primaryColor: '#1E5631',
        secondaryColor: '#A4B494',
    });
    vi.mocked(useBillingDetails).mockReturnValue({
        billingDetails: overrides.noBilling ? undefined : (overrides.billing ?? billingDetails),
        isLoading: false,
        updateBillingDetails: vi.fn(),
    });
    vi.mocked(useEurYears).mockReturnValue(swrResult<ReturnType<typeof useEurYears>>({default_year: 2026, supported: [2024, 2025, 2026], active_years: overrides.activeYears ?? [2024, 2025, 2026], warnings: []}));
    vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>(reference));
    vi.mocked(useEurSettings).mockReturnValue(swrResult<ReturnType<typeof useEurSettings>>({business_year: 2026, default_business_share: 100, sv_mode: 'gsvg', kleinunternehmer: false}));
    vi.mocked(useEurSummary).mockReturnValue(
        overrides.noSummary
            ? ({data: undefined, error: new Error('boom'), isLoading: false, mutate: vi.fn()} as unknown as ReturnType<typeof useEurSummary>)
            : swrResult<ReturnType<typeof useEurSummary>>(overrides.summaryData ?? summary),
    );
    vi.mocked(useEurEntries).mockReturnValue(swrResult<ReturnType<typeof useEurEntries>>({
        entries: [{
            id: 1, year: 2026, month: 5, direction: 'expense' as const, kind_type: 'Software',
            e1a_code: '9190', business_share: 100, amount_cents: 10000, business_amount_cents: 10000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
        }],
        totals: {income_cents: 0, expense_cents: 10000},
    }));
    vi.mocked(useEurMonth).mockReturnValue(swrResult<ReturnType<typeof useEurMonth>>(monthData));
    vi.mocked(useEurDocuments).mockReturnValue(swrResult<ReturnType<typeof useEurDocuments>>({documents: []}));
    vi.mocked(useEurHelpTexts).mockReturnValue(swrResult<ReturnType<typeof useEurHelpTexts>>({help: [
        {kz: '9216', bezeichnung: 'Ergonomisches Mobiliar', hilfetext: 'Ausgaben bis höchstens 300 Euro.', portal_note: null},
        {kz: '9215', bezeichnung: 'Arbeitsplatzpauschale', hilfetext: ' betragen diese höchstens 12.816 Euro (ändert sich jedes Jahr).', portal_note: null},
        {kz: '9217', bezeichnung: 'Arbeitsplatzpauschale', hilfetext: 'Große Pauschale.', portal_note: null},
        {kz: '9225', bezeichnung: 'Pflichtversicherungen', hilfetext: 'SVA-Beträge.', portal_note: 'Bei berechneter Sozialversicherung wird KZ 9225 nicht zusätzlich erfasst (EUE-023, EUE-115): keine Doppelzählung.'},
    ]}));
    vi.mocked(useEurHours).mockReturnValue(swrResult<ReturnType<typeof useEurHours>>({hours: [], total_minutes: 0}));
    vi.mocked(useEurWarehouse).mockReturnValue(swrResult<ReturnType<typeof useEurWarehouse>>({entries: [], total_cents: 0}));
    vi.mocked(useEurApplications).mockReturnValue(swrResult<ReturnType<typeof useEurApplications>>({applications: []}));
    vi.mocked(useEurSignatures).mockReturnValue(swrResult<ReturnType<typeof useEurSignatures>>({signatures: []}));
    vi.mocked(useEurAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurAnalytics>>({by_customer: [], by_project: [], by_month: []}));
}

function renderView(initialEntry = '/steuern') {
    return renderWithProviders(
        <MemoryRouter initialEntries={[initialEntry]}>
            <ManagementEurView/>
        </MemoryRouter>,
    );
}

const main = () => within(screen.getByRole('main', {name: 'Buchhaltung'}));

describe('ManagementEurView', () => {
    it('shows the standalone cockpit with all twelve tabs, the year selector and the booking tab by default', () => {
        mockHooks();

        renderView();

        const view = main();

        expect(screen.getByRole('heading', {name: 'Einnahmen Ausgaben Rechnung · Test-Portal', level: 1})).toBeVisible();
        expect(screen.getByRole('tablist')).toBeVisible();
        expect(screen.getAllByRole('tab')).toHaveLength(12);
        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2026');
        expect(screen.getByText(/Wirtschaftsjahr 2026/)).toBeVisible();
        expect(view.getByRole('heading', {name: 'Buchungen', level: 2})).toBeVisible();
        expect(view.getByRole('heading', {name: 'Übersicht 2026'})).toBeVisible();
        expect(screen.getByRole('link', {name: 'Zurück zum Portal'})).toHaveAttribute('href', '/');
    });

    it('falls back to 2026 when the year parameter is unknown', () => {
        mockHooks();

        renderView('/steuern?jahr=1999');

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2026');
        expect(screen.getByText(/Wirtschaftsjahr 2026/)).toBeVisible();
    });

    it('uses a supported year parameter for the header and the selector', () => {
        mockHooks();

        renderView('/steuern?jahr=2024');

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2024');
        expect(screen.getByText(/Wirtschaftsjahr 2024/)).toBeVisible();
    });

    it('lists only years with bookings plus the current year in the selector', () => {
        mockHooks({activeYears: [2024]});

        renderView('/steuern?jahr=2024');

        const options = Array.from(screen.getByRole('combobox', {name: 'Jahr'}).querySelectorAll('option'))
            .map((option) => option.value);
        expect(options).toEqual(['2024', '2026']);
    });

    it('always offers the current year even when no year has bookings', () => {
        mockHooks({activeYears: []});

        renderView();

        const options = Array.from(screen.getByRole('combobox', {name: 'Jahr'}).querySelectorAll('option'))
            .map((option) => option.value);
        expect(options).toEqual(['2026']);
        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2026');
    });

    it('takes the visible years and the default year from the API response', () => {
        mockHooks();
        vi.mocked(useEurYears).mockReturnValue(swrResult<ReturnType<typeof useEurYears>>({
            default_year: 2025,
            supported: [2024, 2025, 2026, 2027],
            active_years: [2025],
            warnings: [],
        }));

        renderView();

        const selector = screen.getByRole('combobox', {name: 'Jahr'});
        const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
        expect(options).toEqual(['2025', '2026']);
        expect(selector).toHaveValue('2025');
        expect(screen.getByText(/Wirtschaftsjahr 2025/)).toBeVisible();
    });

    it('explains that the month rail only lists months with bookings', () => {
        mockHooks();

        renderView();

        expect(screen.getByText('Es werden nur Monate mit Buchungen angezeigt.')).toBeVisible();
    });

    it('places the tab row above the month rail and the month KPIs', () => {
        mockHooks();

        renderView();

        const tablist = screen.getByRole('tablist');
        const monthRail = screen.getByRole('heading', {name: 'Monate'});
        expect(tablist.compareDocumentPosition(monthRail) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getByText('Monatsübersicht')).toBeVisible();
    });

    it('opens the booking dialog from the header and from the list and closes it again', async () => {
        mockHooks();

        renderView();

        const view = main();

        await userEvent.click(screen.getAllByRole('button', {name: '+ Buchung'})[0]!);
        const dialog = screen.getByRole('dialog', {name: 'Neue Buchung'});
        expect(dialog).toBeVisible();
        expect(within(dialog).getByLabelText('Betrag (€)')).toBeVisible();
        expect(within(dialog).getByRole('button', {name: 'Buchung speichern'})).toBeDisabled();
        await userEvent.click(within(dialog).getByRole('button', {name: 'Abbrechen'}));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        await userEvent.click(view.getByRole('button', {name: '+ Buchung'}));
        expect(screen.getByRole('dialog', {name: 'Neue Buchung'})).toBeVisible();
    });

    it('closes the entry dialog on Escape and keeps Tab focus inside it', async () => {
        mockHooks();

        renderView();

        await userEvent.click(screen.getAllByRole('button', {name: '+ Buchung'})[0]!);
        const dialog = screen.getByRole('dialog', {name: 'Neue Buchung'});
        expect(within(dialog).getByRole('button', {name: 'Schließen'})).toHaveFocus();

        for (let index = 0; index < 12; index += 1) {
            await userEvent.tab();
            expect(dialog.contains(document.activeElement)).toBe(true);
        }

        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('sends only the leading KZ digits of a descriptive E1a label when saving', async () => {
        mockHooks();
        vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>({
            ...reference,
            expense_kinds: [
                {type: 'Software', e1a: '9190 - Provisionen und Lizenzen', share_percent: null},
            ],
        }));
        vi.mocked(createEurEntry).mockClear();

        renderView();

        await userEvent.click(screen.getAllByRole('button', {name: '+ Buchung'})[0]!);
        const dialog = screen.getByRole('dialog', {name: 'Neue Buchung'});
        await userEvent.selectOptions(within(dialog).getByLabelText('Art'), 'Software');
        await userEvent.type(within(dialog).getByLabelText('Betrag (€)'), '100');
        await userEvent.click(within(dialog).getByRole('button', {name: 'Buchung speichern'}));

        expect(createEurEntry).toHaveBeenCalledWith(expect.objectContaining({
            kind_type: 'Software',
            e1a_code: '9190',
            amount_cents: 10000,
        }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('opens every tab and shows its German heading', async () => {
        mockHooks();

        renderView();

        const view = main();

        await userEvent.click(screen.getByRole('tab', {name: 'Wareneingang'}));
        expect(view.getByRole('heading', {name: 'Wareneingang', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Belege'}));
        expect(view.getByRole('heading', {name: 'Belege', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Anlagen'}));
        expect(view.getByRole('heading', {name: 'Abschreibung vergleichen', level: 2})).toBeVisible();
        expect(view.getByText(/unter 1.000 €/)).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Monat'}));
        expect(view.getByRole('heading', {name: 'Monatsabschluss', level: 2})).toBeVisible();
        expect(view.getByRole('heading', {name: 'Stundenbuch', level: 2})).toBeVisible();
        expect(view.getByText('💡 Ergänzen Sie den Beleg im Tab Belege.')).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Auswertungen'}));
        expect(view.getByRole('heading', {name: 'Auswertungen', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Reisen'}));
        expect(view.getByRole('heading', {name: 'Reisekosten', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Steuer'}));
        expect(view.getByRole('heading', {name: 'Einkommensteuer', level: 2})).toBeVisible();
        expect(view.getAllByText('Ergonomisches Mobiliar').length).toBeGreaterThan(0);
        expect(view.getAllByText('Arbeitsplatzpauschale').length).toBeGreaterThan(0);
        expect(view.getByText('9215 / 9217')).toBeVisible();
        await userEvent.click(view.getAllByRole('button', {name: 'Hilfe zu Kennzahl 9215'})[0]);
        expect(view.getAllByRole('tooltip')[0]).toHaveTextContent(/12\.816 Euro \(ändert sich jedes Jahr\)/);
        expect(view.queryByText(/Zelle/)).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('tab', {name: 'USt'}));
        expect(view.getByRole('heading', {name: 'Umsatzsteuer', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Anträge'}));
        expect(view.getByRole('heading', {name: 'Anträge', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Signatur'}));
        expect(view.getByRole('heading', {name: 'Signatur', level: 2})).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Einstellungen'}));
        expect(view.getByRole('heading', {name: 'Unternehmensdaten', level: 2})).toBeVisible();
        expect(view.getByText('Musterfoto GmbH')).toBeVisible();
        expect(view.getByText('742')).toBeVisible();
        expect(view.getByRole('heading', {name: 'Stammdaten und Kataloge', level: 2})).toBeVisible();
        expect(view.getByText('Inland: 30,00 € Tag und 17,00 € Nächtigung.')).toBeVisible();

        await userEvent.click(screen.getByRole('tab', {name: 'Buchungen'}));
        expect(view.getByRole('heading', {name: 'Buchungen', level: 2})).toBeVisible();
    });

    it('shows a placeholder instead of technical or empty internals when settings are missing', async () => {
        mockHooks({noBilling: true});

        renderView();

        const view = main();
        await userEvent.click(screen.getByRole('tab', {name: 'Einstellungen'}));
        expect(view.getAllByText('—').length).toBeGreaterThan(0);
        expect(view.queryByText(/Paritätsvektoren/)).not.toBeInTheDocument();
        expect(view.queryByText(/Cent/)).not.toBeInTheDocument();
    });

    it('offers a concrete solution action when the summary cannot be loaded', () => {
        mockHooks({noSummary: true});

        renderView();

        const view = main();
        expect(view.getByRole('heading', {name: 'Übersicht nicht verfügbar'})).toBeVisible();
        expect(view.getByRole('button', {name: 'Erneut laden'})).toBeVisible();
    });
});
