import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import {
    createEurEntry,
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

// Records every location the router reports. A `replace` navigation produces a
// new key, so a second write from the URL-normalisation effect is observable.
const locationKeys: string[] = [];

function LocationEcho() {
    const location = useLocation();
    locationKeys.push(location.key);
    return <output data-testid="location-search">{location.search}</output>;
}

function effectiveSearch(): URLSearchParams {
    return new URLSearchParams(screen.getByTestId('location-search').textContent ?? '');
}

function mockHooks(overrides: {
    billing?: typeof billingDetails | undefined;
    summaryData?: typeof summary;
    noSummary?: boolean;
    noBilling?: boolean;
    activeYears?: number[];
    pinnedYears?: number[];
    bookableYears?: number[];
    lastPinnedYear?: number;
    defaultYear?: number;
    entryMonths?: number[];
} = {}) {
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
    vi.mocked(useEurYears).mockReturnValue(swrResult<ReturnType<typeof useEurYears>>({
        pinned_years: overrides.pinnedYears ?? [2024, 2025, 2026],
        bookable_years: overrides.bookableYears ?? [2026],
        last_pinned_year: overrides.lastPinnedYear ?? 2026,
        default_year: overrides.defaultYear ?? 2026,
        active_years: overrides.activeYears ?? [2024, 2025, 2026],
    }));
    vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>(reference));
    vi.mocked(useEurSettings).mockReturnValue(swrResult<ReturnType<typeof useEurSettings>>({business_year: 2026, default_business_share: 100, sv_mode: 'gsvg', kleinunternehmer: false}));
    vi.mocked(useEurSummary).mockReturnValue(
        overrides.noSummary
            ? ({data: undefined, error: new Error('boom'), isLoading: false, mutate: vi.fn()} as unknown as ReturnType<typeof useEurSummary>)
            : swrResult<ReturnType<typeof useEurSummary>>(overrides.summaryData ?? summary),
    );
    const entryMonths = overrides.entryMonths ?? [5];
    vi.mocked(useEurEntries).mockReturnValue(swrResult<ReturnType<typeof useEurEntries>>({
        entries: entryMonths.map((month, index) => ({
            id: index + 1, year: 2026, month, direction: 'expense' as const, kind_type: 'Software',
            e1a_code: '9190', business_share: 100, amount_cents: 10000, business_amount_cents: 10000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
        })),
        totals: {income_cents: 0, expense_cents: 10000 * entryMonths.length},
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
    const emptyMatrix = {types: [], month_totals: {}, total_cents: 0, booked_cents: 0, delta_cents: 0, match: true};
    vi.mocked(useEurIncomeTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurIncomeTypeAnalytics>>({year: 2026, kind: 'income', ...emptyMatrix}));
    vi.mocked(useEurExpenseTypeAnalytics).mockReturnValue(swrResult<ReturnType<typeof useEurExpenseTypeAnalytics>>({year: 2026, kind: 'expense', ...emptyMatrix}));
    vi.mocked(useEurPaymentReconciliation).mockReturnValue(swrResult<ReturnType<typeof useEurPaymentReconciliation>>({year: 2026, methods: []}));
}

function eurTree(initialEntry = '/steuern') {
    return (
        <MemoryRouter initialEntries={[initialEntry]}>
            <ManagementEurView/>
            <LocationEcho/>
        </MemoryRouter>
    );
}

function renderView(initialEntry = '/steuern') {
    return renderWithProviders(eurTree(initialEntry));
}

const main = () => within(screen.getByRole('main', {name: 'Buchhaltung'}));

/** Runs the test callback with only `Date` faked, so timers stay real. */
async function withFakeDate(isoDate: string, run: () => void | Promise<void>) {
    vi.useFakeTimers({toFake: ['Date']});
    vi.setSystemTime(new Date(isoDate));
    try {
        await run();
    } finally {
        vi.useRealTimers();
    }
}

describe('ManagementEurView', () => {
    beforeEach(() => {
        locationKeys.length = 0;
    });

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

    it('does not render a year-warning banner while the selected year has pinned rules (EUE-002/003)', () => {
        mockHooks();

        renderView();

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText(/Die Regeln für/)).not.toBeInTheDocument();
    });

    it('warns with both years when the selected year has no pinned rules yet (EUE-002/003)', () => {
        withFakeDate('2027-02-10T12:00:00', () => {
            mockHooks({activeYears: [2026]});

            renderView('/steuern?jahr=2027');

            const alert = screen.getByRole('alert');
            expect(alert).toHaveTextContent('2027');
            expect(alert).toHaveTextContent('2026');
            expect(alert).toHaveTextContent('noch nicht eingepflegt');
            expect(screen.queryByText(/Lifetime/)).not.toBeInTheDocument();
            expect(screen.queryByText(/Lizenz/)).not.toBeInTheDocument();
            expect(screen.queryByText(/neueste Version/)).not.toBeInTheDocument();
        });
    });

    it('keeps the offline fallback banner-free at the pinned year', () => {
        mockHooks();
        vi.mocked(useEurYears).mockReturnValue({
            data: undefined, error: new Error('offline'), isLoading: false, mutate: vi.fn(),
        } as unknown as ReturnType<typeof useEurYears>);

        renderView();

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2026');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('falls back to 2026 when the year parameter is unknown and corrects the URL', () => {
        mockHooks();

        renderView('/steuern?jahr=1999');

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2026');
        expect(screen.getByText(/Wirtschaftsjahr 2026/)).toBeVisible();
        expect(effectiveSearch().get('jahr')).toBe('2026');
    });

    it('uses a valid bookable year parameter for the header and the selector', () => {
        mockHooks({activeYears: [2025, 2026], bookableYears: [2025, 2026]});

        renderView('/steuern?jahr=2025');

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2025');
        expect(screen.getByText(/Wirtschaftsjahr 2025/)).toBeVisible();
    });

    it('offers only bookable years: 2024/2025 stay rule basis, 2026 is selectable', () => {
        mockHooks({activeYears: [2025, 2026], bookableYears: [2026]});

        renderView();

        const selector = screen.getByRole('combobox', {name: 'Jahr'});
        const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
        expect(options).toEqual(['2026']);
        expect(selector).toHaveValue('2026');
    });

    it('does not write the URL before years and entries are loaded (#76)', () => {
        mockHooks();
        vi.mocked(useEurYears).mockReturnValue(
            {data: undefined, error: undefined, isLoading: true, mutate: vi.fn()} as unknown as ReturnType<typeof useEurYears>,
        );
        vi.mocked(useEurEntries).mockReturnValue(
            {data: undefined, error: undefined, isLoading: true, mutate: vi.fn()} as unknown as ReturnType<typeof useEurEntries>,
        );

        renderView();

        expect(screen.getByText(/Wirtschaftsjahr 2026/)).toBeVisible();
        expect(screen.getByTestId('location-search').textContent).toBe('');
        expect(new Set(locationKeys).size).toBe(1);
    });

    it('keeps a deep-linked year once years and entries have loaded (#76)', () => {
        const yearOptions = {activeYears: [2027], bookableYears: [2026], lastPinnedYear: 2026, defaultYear: 2027};
        mockHooks(yearOptions);
        vi.mocked(useEurYears).mockReturnValue(
            {data: undefined, error: undefined, isLoading: true, mutate: vi.fn()} as unknown as ReturnType<typeof useEurYears>,
        );
        vi.mocked(useEurEntries).mockReturnValue(
            {data: undefined, error: undefined, isLoading: true, mutate: vi.fn()} as unknown as ReturnType<typeof useEurEntries>,
        );
        const view = renderView('/steuern?bereich=buchungen&jahr=2027&monat=5');

        // Noch keine Daten: die URL bleibt, wie sie ist.
        expect(screen.getByTestId('location-search')).toHaveTextContent('?bereich=buchungen&jahr=2027&monat=5');

        mockHooks(yearOptions);
        view.rerender(eurTree('/steuern?bereich=buchungen&jahr=2027&monat=5'));

        const selector = screen.getByRole('combobox', {name: 'Jahr'});
        const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
        expect(options).toContain('2027');
        expect(selector).toHaveValue('2027');
        expect(screen.getByText(/Wirtschaftsjahr 2027/)).toBeVisible();
        expect(effectiveSearch().get('jahr')).toBe('2027');
        expect(new Set(locationKeys).size).toBe(1);
    });

    it('corrects an unknown year to default_year instead of the offline fallback (#76)', () => {
        mockHooks({activeYears: [2026, 2027], bookableYears: [2026], lastPinnedYear: 2026, defaultYear: 2027});

        renderView('/steuern?jahr=1999');

        expect(screen.getByRole('combobox', {name: 'Jahr'})).toHaveValue('2027');
        expect(screen.getByText(/Wirtschaftsjahr 2027/)).toBeVisible();
        expect(effectiveSearch().get('jahr')).toBe('2027');
    });

    it('keeps a booked year selectable beyond the pinned bookable base', async () => {
        await withFakeDate('2026-05-10T12:00:00', async () => {
            mockHooks({activeYears: [2026, 2027], bookableYears: [2026]});
            vi.mocked(useEurEntries).mockClear();

            renderView();

            const selector = screen.getByRole('combobox', {name: 'Jahr'});
            const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
            expect(options).toEqual(['2026', '2027']);

            await userEvent.selectOptions(selector, '2027');

            const calls = vi.mocked(useEurEntries).mock.calls;
            expect(calls[calls.length - 1]?.[0]).toBe(2027);
            expect(effectiveSearch().get('jahr')).toBe('2027');
            expect(screen.getByText(/Wirtschaftsjahr 2027/)).toBeVisible();
        });
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
            pinned_years: [2024, 2025, 2026, 2027],
            bookable_years: [2025, 2026],
            last_pinned_year: 2027,
            default_year: 2025,
            active_years: [2025],
        }));

        renderView();

        const selector = screen.getByRole('combobox', {name: 'Jahr'});
        const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
        expect(options).toEqual(['2025', '2026']);
        expect(selector).toHaveValue('2025');
        expect(screen.getByText(/Wirtschaftsjahr 2025/)).toBeVisible();
    });

    it('writes the effective state into the URL for a bare /steuern entry (#76)', () => {
        mockHooks();

        renderView();

        const search = effectiveSearch();
        expect(search.get('bereich')).toBe('buchungen');
        expect(search.get('jahr')).toBe('2026');
        expect(search.get('monat')).toBe('5');
        expect(screen.getByRole('heading', {name: 'Mai 2026'})).toBeVisible();
    });

    it('keeps a valid deep link stable without a second history entry (#76)', () => {
        mockHooks();

        renderView('/steuern?bereich=buchungen&jahr=2026&monat=5');

        expect(new Set(locationKeys).size).toBe(1);
        expect(screen.getByTestId('location-search')).toHaveTextContent('?bereich=buchungen&jahr=2026&monat=5');
    });

    it('corrects an invalid month parameter to the rendered month (#76)', () => {
        mockHooks();

        renderView('/steuern?monat=99');

        expect(screen.getByRole('heading', {name: 'Mai 2026'})).toBeVisible();
        expect(effectiveSearch().get('monat')).toBe('5');
    });

    it('offers the running year beyond the last pinned year and defaults to the API default (#78)', () => {
        withFakeDate('2027-01-15T12:00:00', () => {
            mockHooks({activeYears: [2026]});

            renderView();

            const selector = screen.getByRole('combobox', {name: 'Jahr'});
            const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
            expect(options).toEqual(['2026', '2027']);
            expect(selector).toHaveValue('2026');
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        });
    });

    it('shows the rules hint once the running year beyond the pinned basis is selected (#75/#78)', async () => {
        await withFakeDate('2027-01-15T12:00:00', async () => {
            mockHooks({activeYears: [2026]});

            renderView();

            await userEvent.selectOptions(screen.getByRole('combobox', {name: 'Jahr'}), '2027');

            const alert = screen.getByRole('alert');
            expect(alert).toHaveTextContent('2027');
            expect(alert).toHaveTextContent('2026');
            expect(effectiveSearch().get('jahr')).toBe('2027');
        });
    });

    it('falls back to the last pinned year when no year option is available (#78)', () => {
        withFakeDate('2024-01-15T12:00:00', () => {
            mockHooks({pinnedYears: [2025, 2026], lastPinnedYear: 2026, defaultYear: 2026, activeYears: []});

            renderView();

            const selector = screen.getByRole('combobox', {name: 'Jahr'});
            const options = Array.from(selector.querySelectorAll('option')).map((option) => option.value);
            expect(options).toEqual(['2026']);
            expect(selector).toHaveValue('2026');
        });
    });

    it('keeps the running month when it has bookings (#77)', () => {
        withFakeDate('2026-03-15T12:00:00', () => {
            mockHooks({entryMonths: [2, 3]});

            renderView();

            expect(screen.getByRole('heading', {name: 'März 2026'})).toBeVisible();
            expect(effectiveSearch().get('monat')).toBe('3');
        });
    });

    it('falls back to the latest booked month when the running month has none (#77)', () => {
        withFakeDate('2026-03-15T12:00:00', () => {
            mockHooks({entryMonths: [1, 2]});

            renderView();

            expect(screen.getByRole('heading', {name: 'Februar 2026'})).toBeVisible();
            expect(effectiveSearch().get('monat')).toBe('2');
        });
    });

    it('falls back to the running month when the year has no bookings (#77)', () => {
        withFakeDate('2026-03-15T12:00:00', () => {
            mockHooks({entryMonths: []});

            renderView();

            expect(screen.getByRole('heading', {name: 'März 2026'})).toBeVisible();
            const month = Number(effectiveSearch().get('monat'));
            expect(month).toBeGreaterThanOrEqual(1);
            expect(month).toBeLessThanOrEqual(12);
            expect(month).toBe(3);
        });
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
        expect(view.getByText(/Anschaffungen unter 1\.000 € können Sie nur im Jahr des Kaufs direkt als Ausgabe erfassen/)).toBeVisible();
        expect(view.getByText(/Privatanlagen werden immer linear über die Nutzungsjahre abgeschrieben/)).toBeVisible();

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

    it('marks the EUR tablist with the scoped eur-tabs class for the contrast override', () => {
        mockHooks();

        renderView();

        expect(screen.getByRole('tablist')).toHaveClass('eur-tabs');
    });

    it('binds the title separator and portal name in a nowrap span so the dot cannot wrap alone', () => {
        mockHooks();

        renderView();

        const heading = screen.getByRole('heading', {level: 1});
        const separator = within(heading).getByText('· Test-Portal');
        expect(separator).toHaveClass('whitespace-nowrap');
        expect(separator.tagName).toBe('SPAN');
        expect(heading).toHaveTextContent('Einnahmen Ausgaben Rechnung · Test-Portal');
    });
});
