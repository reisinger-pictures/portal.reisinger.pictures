import {describe, expect, it, vi} from 'vitest';
import {screen, within} from '@testing-library/react';
import {
    useEurProjectAnalytics,
    type EurProjectAnalytics,
    type EurProjectAnalyticsCheck,
    type EurProjectAnalyticsMonth,
    type EurProjectAnalyticsRow,
} from '../../../../logic/useEur';
import {renderWithProviders} from '../../../../test-setup';
import EurProjectAnalyticsPanel from '../EurProjectAnalyticsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurProjectAnalytics: vi.fn()};
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

type CellValues = Omit<EurProjectAnalyticsMonth, 'month'>;

function monthsWith(month: number, values: CellValues): EurProjectAnalyticsMonth[] {
    return Array.from({length: 12}, (_, index) => ({
        month: index + 1,
        income_cents: index + 1 === month ? values.income_cents : 0,
        expense_cents: index + 1 === month ? values.expense_cents : 0,
        hours_minutes: index + 1 === month ? values.hours_minutes : 0,
        result_cents: index + 1 === month ? values.result_cents : 0,
        result_per_hour_cents: index + 1 === month ? values.result_per_hour_cents : null,
    }));
}

const alphaValues: CellValues = {
    income_cents: 100000,
    expense_cents: 40000,
    hours_minutes: 180,
    result_cents: 60000,
    result_per_hour_cents: 333,
};
const betaValues: CellValues = {
    income_cents: 50000,
    expense_cents: 20000,
    hours_minutes: 0,
    result_cents: 30000,
    result_per_hour_cents: null,
};

const projects: EurProjectAnalyticsRow[] = [
    {project: 'Projekt Alpha', months: monthsWith(1, alphaValues), totals: alphaValues},
    {project: 'Projekt Beta', months: monthsWith(2, betaValues), totals: betaValues},
];

const checks: EurProjectAnalyticsCheck[] = [
    {code: 'P121-INCOME', ok: true, message: 'Einnahmen OK', hint: '💡 Keine Maßnahme nötig.'},
    {code: 'P121-EXPENSE', ok: true, message: 'Ausgaben OK', hint: '💡 Keine Maßnahme nötig.'},
    {code: 'P121-HOURS', ok: true, message: 'Stunden OK', hint: '💡 Keine Maßnahme nötig.'},
];

function analytics(overrides: Partial<EurProjectAnalytics> = {}): EurProjectAnalytics {
    return {
        year: 2026,
        projects,
        month_totals: Array.from({length: 12}, (_, index) => ({
            month: index + 1,
            income_cents: index === 0 ? 100000 : index === 1 ? 50000 : 0,
            expense_cents: index === 0 ? 40000 : index === 1 ? 20000 : 0,
            hours_minutes: index === 0 ? 180 : 0,
            result_cents: index === 0 ? 60000 : index === 1 ? 30000 : 0,
        })),
        totals: {
            income_cents: 150000,
            expense_cents: 60000,
            hours_minutes: 180,
            result_cents: 90000,
            result_per_hour_cents: 500,
        },
        undistributed_hours_minutes: 60,
        prior_year_afa_cents: 12345,
        sv_excluded_cents: 9999,
        checks,
        match: true,
        ...overrides,
    };
}

function renderPanel(data: EurProjectAnalytics = analytics()) {
    vi.mocked(useEurProjectAnalytics).mockReturnValue(swrResult(data));
    return renderWithProviders(<EurProjectAnalyticsPanel year={2026}/>);
}

describe('EurProjectAnalyticsPanel', () => {
    it('renders the project × month values, reconciliation and AfA/SV summary', () => {
        renderPanel();

        const panel = screen.getByTestId('eur-project-analytics-panel');
        expect(within(panel).getByRole('heading', {name: /Projekte-Auswertung/})).toBeVisible();
        expect(within(panel).getByRole('columnheader', {name: 'Projekt'})).toBeVisible();
        expect(within(panel).getByRole('columnheader', {name: 'Jän'})).toBeVisible();
        expect(within(panel).getByRole('columnheader', {name: 'Dez'})).toBeVisible();
        expect(within(panel).getByRole('columnheader', {name: 'Cent pro Minute'})).toBeVisible();

        const january = within(panel).getByTestId('eur-project-cell-Projekt Alpha-1');
        expect(within(january).getByText('1.000,00 €')).toBeVisible();
        expect(within(january).getByText('400,00 €')).toBeVisible();
        expect(within(january).getByText('180 Min.')).toBeVisible();
        expect(within(january).getByText('600,00 €')).toBeVisible();

        const february = within(panel).getByTestId('eur-project-cell-Projekt Beta-2');
        expect(within(february).getByText('500,00 €')).toBeVisible();
        expect(within(february).getByText('200,00 €')).toBeVisible();
        expect(within(february).getByText('300,00 €')).toBeVisible();

        expect(within(panel).getByText(/✓✓ Abgleich Projekte/)).toBeVisible();
        expect(within(panel).getByText(/^Vorjahres-AfA/)).toBeVisible();
        expect(within(panel).getByText('123,45 €')).toBeVisible();
        expect(within(panel).getByText(/SV-Bereinigung/)).toBeVisible();
        expect(within(panel).getByText('99,99 €')).toBeVisible();
        expect(within(panel).getByText(/Stunden ohne Projekt/)).toBeVisible();
        expect(within(panel).getByText('60 Min.')).toBeVisible();
        expect(within(panel).getByText(/keinem Projekt zuordnen/)).toBeVisible();
    });

    it('shows the deviation message when a check fails', () => {
        renderPanel(analytics({
            match: false,
            checks: [
                {code: 'P121-EXPENSE', ok: false, message: '⚠ Ausgaben fehlen.', hint: '💡 Bitte prüfen.'},
            ],
        }));

        expect(screen.getByText(/Δ Abweichung Projekte/)).toBeVisible();
        expect(screen.getByText(/Ausgaben fehlen/)).toBeVisible();
    });

    it('renders the empty state without a table when there is no data', () => {
        renderPanel(analytics({projects: [], match: true}));

        expect(screen.getByText(/Noch keine Buchungen oder Stunden für die Projekte-Auswertung/)).toBeVisible();
        expect(screen.queryByRole('table')).toBeNull();
    });
});
