import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { useEurMonth, type EurMonthData } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurMonthPanel from '../EurMonthPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurMonth: vi.fn(), closeEurMonth: vi.fn()};
});

const monthData: EurMonthData = {
    year: 2026,
    month: 5,
    lines: [],
    totals: {income_cents: 0, expense_cents: 0, warehouse_cents: 0, vat_cents: 0, hours_minutes: 0},
    checks: [],
    social_insurance: {amounts_cents: {}, rates_per_10000: {}},
    income_tax: {profit_cents: 0, tax_cents: 0},
    report: {status: 'open', closed_at: null},
    signature: {status: 'none', provider: null, is_test: false},
};

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function renderPanel(data: EurMonthData) {
    vi.mocked(useEurMonth).mockReturnValue(swrResult<ReturnType<typeof useEurMonth>>(data));
    return renderWithProviders(<EurMonthPanel year={2026} month={5} onMonthChange={vi.fn()}/>);
}

describe('EurMonthPanel E1A/E1B rows', () => {
    it('renders per-code rows when e1_by_code is present', () => {
        renderPanel({
            ...monthData,
            e1_by_code: {
                '9040': {income_cents: 50000, expense_cents: 0},
                '9230': {income_cents: 0, expense_cents: 12000},
            },
        });

        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('9040')).toBeVisible();
        expect(screen.getByText('9230')).toBeVisible();
        expect(screen.getAllByText(/120,00/).length).toBeGreaterThan(0);
    });

    it('renders the grouped E1A/E1B shape delivered by the month endpoint', () => {
        renderPanel({
            ...monthData,
            e1_by_code: {
                E1A: {'9130': 5000},
                E1B: {'9500': 3000},
            },
        });

        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('E1A 9130')).toBeVisible();
        expect(screen.getByText('E1B 9500')).toBeVisible();
        expect(screen.getByText(/50,00/)).toBeVisible();
    });

    it('keeps the existing rendering when e1_by_code is absent', () => {
        renderPanel(monthData);

        expect(screen.queryByRole('heading', {name: 'E1A/E1B je Kennzahl'})).not.toBeInTheDocument();
        expect(screen.getByRole('heading', {name: 'Monatsabschluss'})).toBeVisible();
    });
});
