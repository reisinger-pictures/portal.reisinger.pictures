import {describe, expect, it, vi} from 'vitest';
import {screen, within} from '@testing-library/react';
import {
    useEaExpenseTypeAnalytics,
    useEaIncomeTypeAnalytics,
    useEaPaymentReconciliation,
    type EaTypeMatrix,
} from '../../../../logic/useEa';
import {renderWithProviders} from '../../../../test-setup';
import EaAnalyticsMatricesPanel from '../EaAnalyticsMatricesPanel';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {
        ...actual,
        useEaIncomeTypeAnalytics: vi.fn(),
        useEaExpenseTypeAnalytics: vi.fn(),
        useEaPaymentReconciliation: vi.fn(),
    };
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

const incomeMatrix: EaTypeMatrix = {
    year: 2026,
    kind: 'income',
    types: [
        {type: 'Dienstleistung', months: {1: 100000, 2: 100000}, total_cents: 200000},
        {type: 'Miete', months: {1: 50000}, total_cents: 50000},
    ],
    month_totals: {1: 150000, 2: 100000},
    total_cents: 250000,
    booked_cents: 250000,
    delta_cents: 0,
    match: true,
};

const expenseMatrix: EaTypeMatrix = {
    year: 2026,
    kind: 'expense',
    types: [{
        type: 'Software',
        months: {1: 20000},
        total_cents: 20000,
        sources: {A: 10000, AV: 5000, WEB: 3000, FR: 2000},
    }],
    month_totals: {1: 20000},
    total_cents: 20000,
    booked_cents: 25000,
    delta_cents: -5000,
    match: false,
};

describe('EaAnalyticsMatricesPanel', () => {
    function renderPanel() {
        vi.mocked(useEaIncomeTypeAnalytics).mockReturnValue(swrResult(incomeMatrix));
        vi.mocked(useEaExpenseTypeAnalytics).mockReturnValue(swrResult(expenseMatrix));
        vi.mocked(useEaPaymentReconciliation).mockReturnValue(swrResult({
            year: 2026,
            methods: [{
                method: 'Bar',
                opening_balance_cents: 10000,
                closing_balance_cents: 90000,
                status: '✓✓',
                months: [{month: 1, in_cents: 100000, out_cents: 20000, closing_cents: 90000}],
            }],
        }));
        return renderWithProviders(<EaAnalyticsMatricesPanel year={2026}/>);
    }

    it('renders the income/expense matrices with percentage column and ✓✓/Δ reconciliation', () => {
        renderPanel();

        expect(screen.getByText('Einnahmen nach Art (BE-ART)')).toBeVisible();
        expect(screen.getByText('Ausgaben nach Art (BA-ART)')).toBeVisible();
        expect(screen.getByText('Zahlungsart (ZA)')).toBeVisible();

        // 200.000 / 250.000 = 80,0 %; 50.000 / 250.000 = 20,0 % (eine Stelle).
        expect(screen.getByText('80,0 %')).toBeVisible();
        expect(screen.getByText('20,0 %')).toBeVisible();
        expect(screen.getByText(/✓✓ Abgleich mit dem Journal/)).toBeVisible();
        expect(screen.getByText(/Δ Abweichung zum Journal/)).toBeVisible();

        // BA-ART zeigt die Herkunft A/AV/WEB/FR inklusive FR.
        expect(screen.getByText(/FR 20,00\s*€/)).toBeVisible();
    });

    it('renders the payment-reconciliation row with opening, flows, closing and status', () => {
        renderPanel();

        // Der Zahlungsart-Kontext ist eindeutig über die Spaltenüberschriften.
        expect(screen.getByText('Startsaldo')).toBeVisible();
        expect(screen.getByText('Zuflüsse')).toBeVisible();
        expect(screen.getByText('Abflüsse')).toBeVisible();
        expect(screen.getByText('Endsaldo')).toBeVisible();
        const bar = screen.getByText('Bar');
        expect(bar).toBeVisible();
        const row = bar.closest('tr');
        expect(row).not.toBeNull();
        const za = within(row as HTMLElement);
        expect(za.getByText('✓✓')).toBeVisible();
        expect(za.getByText('1.000,00 €')).toBeVisible();
    });
});
