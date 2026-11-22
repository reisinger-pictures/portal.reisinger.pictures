import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { useEurAnalytics, type EurAnalyticsMonth } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurAnalyticsPanel from '../EurAnalyticsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurAnalytics: vi.fn()};
});

/** The API zero-fills all twelve months even when there are no bookings. */
const zeroMonths: EurAnalyticsMonth[] = Array.from({length: 12}, (_, index) => ({
    month: index + 1,
    income_cents: 0,
    expense_cents: 0,
    profit_cents: 0,
}));

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function analyticsResult(byMonth: EurAnalyticsMonth[]) {
    return swrResult<ReturnType<typeof useEurAnalytics>>({
        by_customer: [],
        by_project: [],
        by_month: byMonth,
    });
}

describe('EurAnalyticsPanel months empty state', () => {
    it('shows the empty message instead of a zero-filled months table', () => {
        vi.mocked(useEurAnalytics).mockReturnValue(analyticsResult(zeroMonths));

        renderWithProviders(<EurAnalyticsPanel year={2026}/>);

        expect(screen.getByText(/Noch keine Monatswerte vorhanden/)).toBeVisible();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('renders the months table once at least one month has a non-zero value', () => {
        const monthWithValues = zeroMonths.map((month, index) =>
            index === 4 ? {...month, income_cents: 12500, profit_cents: 10000} : month,
        );
        vi.mocked(useEurAnalytics).mockReturnValue(analyticsResult(monthWithValues));

        renderWithProviders(<EurAnalyticsPanel year={2026}/>);

        expect(screen.queryByText(/Noch keine Monatswerte vorhanden/)).not.toBeInTheDocument();
        const table = screen.getByRole('table');
        expect(within(table).getByText('Mai')).toBeVisible();
        expect(within(table).getByText(/125,00/)).toBeVisible();
        expect(within(table).getByText(/100,00/)).toBeVisible();
    });
});
