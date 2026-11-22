import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    calculateEurIncomeTax,
    useEurHelpTexts,
    type EurIncomeTaxResult,
} from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurIncomeTaxPanel from '../EurIncomeTaxPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, calculateEurIncomeTax: vi.fn(), useEurHelpTexts: vi.fn()};
});

const result: EurIncomeTaxResult = {
    year: 2026,
    profit_cents: 100000,
    basic_allowance_cents: 10000,
    taxable_cents: 90000,
    income_tax_cents: 15000,
    tax_free_threshold_cents: 12000,
    stages: [{from_cents: 0, to_cents: null, rate_per_10000: 2000}],
    kz: {'245': 100000, '260': 15000, U13: 1000, U26: 2000, E39: 30000, E40: 40000},
    steuerbilanz: {
        betriebliche_einnahmen_cents: 100000,
        betriebliche_ausgaben_cents: 20000,
        gewinn_cents: 80000,
        steuerlicher_gewinn_cents: 68000,
        afa_cents: 5000,
        pauschalen: {e40_code: '9215', e40_label: 'Arbeitsplatzpauschale', e39_code: null, e39_label: null},
    },
};

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function renderPanel(e1ByCode?: EurIncomeTaxResult['e1_by_code']) {
    vi.mocked(useEurHelpTexts).mockReturnValue(swrResult<ReturnType<typeof useEurHelpTexts>>({help: []}));
    return renderWithProviders(
        <EurIncomeTaxPanel defaultProfitCents={100000} e1ByCode={e1ByCode}/>,
    );
}

describe('EurIncomeTaxPanel completion controls', () => {
    it('offers a German Rechenmodus selector wired to the calculation request', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        const selector = screen.getByRole('combobox', {name: 'Rechenmodus'});
        expect(selector).toHaveValue('total');
        expect(within(selector).getByRole('option', {name: 'Gesamt'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur Betrieb'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur Vermietung'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Prognose'})).toBeVisible();

        await userEvent.selectOptions(selector, 'business');
        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(calculateEurIncomeTax).toHaveBeenCalledWith(100000, {mode: 'business'});
    });

    it('renders the additional KZ values and the Steuerbilanz after a calculation', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(screen.getByRole('heading', {name: 'Weitere Kennzahlen'})).toBeVisible();
        expect(screen.getByText('Kennzahl 245')).toBeVisible();
        expect(screen.getByText('Kennzahl U13')).toBeVisible();
        expect(screen.getByText('Kennzahl E39 (Ergonomisches Mobiliar)')).toBeVisible();
        expect(screen.getByRole('heading', {name: 'Steuerbilanz'})).toBeVisible();
        expect(screen.getByText('Betriebliche Einnahmen')).toBeVisible();
        expect(screen.getByText('Betriebliche Ausgaben')).toBeVisible();
        expect(screen.getByRole('heading', {name: 'Einordnung der Pauschalen'})).toBeVisible();
        expect(screen.getByText(/E1A 9215: Arbeitsplatzpauschale/)).toBeVisible();
    });

    it('renders grouped E1A/E1B rows from the tax response when month data is absent', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue({
            ...result,
            e1a_e1b: {E1A: {'9130': 5000}, E1B: {'9500': 3000}},
        });
        renderPanel();

        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('E1A 9130')).toBeVisible();
        expect(screen.getByText('E1B 9500')).toBeVisible();
    });

    it('renders E1A/E1B rows from the month data without a calculation', () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel({'9040': {income_cents: 50000, expense_cents: 0}, 'E1B 9460': {income_cents: 20000, expense_cents: 0}});

        expect(screen.getByRole('heading', {name: 'E1A/E1B je Kennzahl'})).toBeVisible();
        expect(screen.getByText('9040')).toBeVisible();
        expect(screen.getByText('E1B 9460')).toBeVisible();
        expect(screen.queryByRole('heading', {name: 'Weitere Kennzahlen'})).not.toBeInTheDocument();
    });
});
