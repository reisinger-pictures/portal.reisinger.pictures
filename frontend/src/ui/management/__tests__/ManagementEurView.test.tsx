import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import { useEurEntries, useEurReference, useEurSettings, useEurSummary } from '../../../logic/useEur';
import { renderWithProviders } from '../../../test-setup';
import ManagementEurView from '../ManagementEurView';

vi.mock('../../../logic/useBrand', () => ({
    useBrand: vi.fn(),
}));

vi.mock('../../../logic/useLicenseTerms', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useLicenseTerms')>();
    return {...actual, useBillingDetails: vi.fn()};
});

vi.mock('../../../logic/useEur', () => ({
    useEurReference: vi.fn(),
    useEurSettings: vi.fn(),
    useEurSummary: vi.fn(),
    useEurEntries: vi.fn(),
    createEurEntry: vi.fn(),
    fetchEurComparison: vi.fn(),
    calculateEurVat: vi.fn(),
    calculateEurTravel: vi.fn(),
    calculateEurIncomeTax: vi.fn(),
}));

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

function mockHooks(overrides: {billing?: typeof billingDetails | undefined; summaryData?: typeof summary; noSummary?: boolean; noBilling?: boolean} = {}) {
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
    vi.mocked(useEurReference).mockReturnValue({
        data: reference,
        error: undefined,
        isLoading: false,
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useEurReference>);
    vi.mocked(useEurSettings).mockReturnValue({
        data: {business_year: 2026, default_business_share: 100, sv_mode: 'gsvg', kleinunternehmer: false},
        error: undefined,
        isLoading: false,
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useEurSettings>);
    vi.mocked(useEurSummary).mockReturnValue({
        data: overrides.noSummary ? undefined : (overrides.summaryData ?? summary),
        error: overrides.noSummary ? new Error('boom') : undefined,
        isLoading: false,
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useEurSummary>);
    vi.mocked(useEurEntries).mockReturnValue({
        data: {
            entries: [{
                id: 1, year: 2026, month: 5, direction: 'expense' as const, kind_type: 'Software',
                e1a_code: '9190', business_share: 100, amount_cents: 10000, business_amount_cents: 10000,
                vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
            }],
            totals: {income_cents: 0, expense_cents: 10000},
        },
        error: undefined,
        isLoading: false,
        mutate: vi.fn(),
    } as unknown as ReturnType<typeof useEurEntries>);
}

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/eur']}>
            <main>
                <ManagementEurView/>
            </main>
        </MemoryRouter>,
    );
}

describe('ManagementEurView', () => {
    it('shows the standalone cockpit with the month overview and the booking tab by default', () => {
        mockHooks();

        renderView();

        const main = within(screen.getByRole('main'));

        expect(main.getByRole('heading', { name: 'EÜR · Test-Portal', level: 1 })).toBeVisible();
        expect(main.getByRole('tablist')).toBeVisible();
        expect(main.getByRole('heading', { name: /Jänner 2026|Buchungen/ })).toBeVisible();
        expect(main.getByRole('heading', { name: 'Buchungen', level: 2 })).toBeVisible();
        expect(main.getByRole('heading', { name: 'Übersicht 2026' })).toBeVisible();
        expect(main.getByRole('link', { name: 'Zurück zum Portal' })).toHaveAttribute('href', '/');
    });

    it('switches between the sub-application tabs', async () => {
        mockHooks();

        renderView();

        const main = within(screen.getByRole('main'));

        await userEvent.click(main.getByRole('tab', { name: 'Einstellungen' }));
        expect(main.getByRole('heading', { name: 'Unternehmensdaten', level: 2 })).toBeVisible();
        expect(main.getByText('Musterfoto GmbH')).toBeVisible();
        expect(main.getByText('742')).toBeVisible();
        expect(main.getByRole('heading', { name: 'Stammdaten und Kataloge', level: 2 })).toBeVisible();
        expect(main.getByText('Inland: 30,00 € Tag und 17,00 € Nächtigung.')).toBeVisible();

        await userEvent.click(main.getByRole('tab', { name: 'Anlagen' }));
        expect(main.getByRole('heading', { name: 'Abschreibung vergleichen', level: 2 })).toBeVisible();
        expect(main.getByText(/unter 1.000 €/)).toBeVisible();

        await userEvent.click(main.getByRole('tab', { name: 'Steuer' }));
        expect(main.getByRole('heading', { name: 'Einkommensteuer', level: 2 })).toBeVisible();

        await userEvent.click(main.getByRole('tab', { name: 'USt' }));
        expect(main.getByRole('heading', { name: 'Umsatzsteuer', level: 2 })).toBeVisible();

        await userEvent.click(main.getByRole('tab', { name: 'Reisen' }));
        expect(main.getByRole('heading', { name: 'Reisekosten', level: 2 })).toBeVisible();

        await userEvent.click(main.getByRole('tab', { name: 'Monat' }));
        expect(main.getByText('Zeilen des Monats aus dem Buchungsjournal.')).toBeVisible();
    });

    it('shows a placeholder instead of technical or empty internals when settings are missing', async () => {
        mockHooks({noBilling: true});

        renderView();

        const main = within(screen.getByRole('main'));
        await userEvent.click(main.getByRole('tab', { name: 'Einstellungen' }));
        expect(main.getAllByText('—').length).toBeGreaterThan(0);
        expect(main.queryByText(/Paritätsvektoren/)).not.toBeInTheDocument();
        expect(main.queryByText(/Cent/)).not.toBeInTheDocument();
    });

    it('offers a concrete solution action when the summary cannot be loaded', () => {
        mockHooks({noSummary: true});

        renderView();

        const main = within(screen.getByRole('main'));
        expect(main.getByRole('heading', { name: 'Übersicht nicht verfügbar' })).toBeVisible();
        expect(main.getByRole('button', { name: 'Erneut laden' })).toBeVisible();
    });
});
