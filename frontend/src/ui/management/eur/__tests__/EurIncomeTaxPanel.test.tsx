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

const pauschalen = {e40_code: '9215', e40_label: 'Arbeitsplatzpauschale', e39_code: null, e39_label: null};
const steuerbilanz = {
    betriebliche_einnahmen_cents: 100000,
    betriebliche_ausgaben_cents: 20000,
    gewinn_cents: 80000,
    steuerlicher_gewinn_cents: 68000,
    afa_cents: 5000,
    pauschalen,
};
const est_allocation = {
    total_income_cents: 1000000,
    tax_cents: 380800,
    employment_tax_cents: 129220,
    rental_tax_cents: 28000,
    business_tax_cents: 223580,
    due_cents: 230800,
    prepayment_cents: 10000,
    wage_tax_cents: 12000,
};

const result: EurIncomeTaxResult = {
    year: 2026,
    profit_cents: 100000,
    basic_allowance_cents: 10000,
    taxable_cents: 90000,
    income_tax_cents: 15000,
    tax_free_threshold_cents: 12000,
    stages: [{from_cents: 0, to_cents: null, rate_per_10000: 2000}],
    kz: {'245': 100000, '260': 15000, U13: 1000, U26: 2000, E39: 30000, E40: 40000},
    steuerbilanz,
    est_allocation,
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
    it('offers six German Rechenmodus options and sends every completed input', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        const selector = screen.getByRole('combobox', {name: 'Rechenmodus'});
        expect(selector).toHaveValue('total');
        expect(within(selector).getByRole('option', {name: 'Gesamt'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur Betrieb'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur Vermietung'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Prognose'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur Bruttolohn'})).toBeVisible();
        expect(within(selector).getByRole('option', {name: 'Nur andere Gewinne'})).toBeVisible();

        await userEvent.selectOptions(selector, 'business');
        await userEvent.type(screen.getByRole('textbox', {name: 'Bruttolohn/des Einkommens (KZ 245)'}), '1000');
        await userEvent.type(screen.getByRole('textbox', {name: 'Bezahlte Lohnsteuer (KZ 260)'}), '200');
        await userEvent.type(screen.getByRole('textbox', {name: 'Einkommensteuer-Vorauszahlung'}), '100');
        await userEvent.type(screen.getByRole('textbox', {name: 'Ergonomisches Mobiliar (E39)'}), '100');
        await userEvent.type(screen.getByRole('textbox', {name: 'Arbeitsplatzpauschale (E40)'}), '500');
        await userEvent.type(screen.getByRole('textbox', {name: 'Arbeitszimmer (KZ 9275)'}), '50');
        await userEvent.selectOptions(screen.getByRole('combobox', {name: 'Prognose'}), 'ja');
        await userEvent.type(screen.getByRole('textbox', {name: 'Prognose des Betriebsergebnisses (€)'}), '250');
        await userEvent.type(screen.getByRole('textbox', {name: 'Prognose des Vermietungsergebnisses (€)'}), '30');
        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(calculateEurIncomeTax).toHaveBeenCalledWith(100000, {
            mode: 'business',
            employment_income_cents: 100000,
            wage_tax_cents: 20000,
            prepayment_cents: 10000,
            ergonomisches_mobiliar_cents: 10000,
            arbeitsplatzpauschale_cents: 50000,
            arbeitszimmer_cents: 5000,
            prognose: true,
            business_forecast_cents: 25000,
            rental_forecast_cents: 3000,
        });
    });

    it('omits empty inputs and sends a Nein prognose explicitly', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        await userEvent.selectOptions(screen.getByRole('combobox', {name: 'Rechenmodus'}), 'gross_wage');
        await userEvent.selectOptions(screen.getByRole('combobox', {name: 'Prognose'}), 'nein');
        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(calculateEurIncomeTax).toHaveBeenCalledWith(100000, {mode: 'gross_wage', prognose: false});
    });

    it('validates E40 and E39 inline before sending', async () => {
        vi.mocked(calculateEurIncomeTax).mockClear();
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        const workplace = screen.getByRole('textbox', {name: 'Arbeitsplatzpauschale (E40)'});
        const ergonomics = screen.getByRole('textbox', {name: 'Ergonomisches Mobiliar (E39)'});
        const button = screen.getByRole('button', {name: 'Steuer berechnen'});

        await userEvent.type(workplace, '24');
        expect(screen.getByText('Für E40 sind 25 € bis 1.200 € erlaubt.')).toBeVisible();
        expect(button).toBeDisabled();

        await userEvent.clear(workplace);
        await userEvent.type(workplace, '1201');
        expect(screen.getByText('Für E40 sind 25 € bis 1.200 € erlaubt.')).toBeVisible();

        await userEvent.clear(workplace);
        await userEvent.type(workplace, '25');
        expect(screen.queryByText('Für E40 sind 25 € bis 1.200 € erlaubt.')).not.toBeInTheDocument();

        await userEvent.type(ergonomics, '301');
        expect(screen.getByText('Für E39 sind 0 € bis 300 € erlaubt.')).toBeVisible();
        expect(button).toBeDisabled();

        await userEvent.clear(ergonomics);
        await userEvent.type(ergonomics, '300');
        expect(button).toBeEnabled();
        expect(calculateEurIncomeTax).not.toHaveBeenCalled();
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

    it('shows the EUE-101/102 warnings from the response next to the Pauschalen', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue({
            ...result,
            steuerbilanz: {
                ...steuerbilanz,
                pauschalen: {
                    ...pauschalen,
                    e40_warning: 'Großes Arbeitsplatzpauschale (9217)',
                    e39_warning: 'Wert löschen (wegen 9217 große Pauschale) →',
                },
            },
        });
        renderPanel();

        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(screen.getByText('Großes Arbeitsplatzpauschale (9217)')).toBeVisible();
        expect(screen.getByText('Wert löschen (wegen 9217 große Pauschale) →')).toBeVisible();
    });

    it('shows the EUE-103 allocation with the pinned German U-code labels', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue(result);
        renderPanel();

        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        expect(screen.getByRole('heading', {name: 'Aufteilung der Einkommensteuer'})).toBeVisible();
        const expectations: Array<[string, string, string]> = [
            ['U23', 'Endsteuer', '3.808,00'],
            ['U27', 'Lohnsteueranteil', '1.292,20'],
            ['U25', 'Vermietungsanteil', '280,00'],
            ['U24', 'Betriebsanteil', '2.235,80'],
            ['U29', 'Zahllast', '2.308,00'],
        ];
        for (const [code, label, amount] of expectations) {
            const row = screen.getByText(label).closest('tr');
            expect(row).not.toBeNull();
            expect(row).toHaveTextContent(code);
            expect(row).toHaveTextContent(amount);
        }
    });

    it('shows an empty Betriebsanteil in the Bruttolohn mode', async () => {
        vi.mocked(calculateEurIncomeTax).mockResolvedValue({
            ...result,
            est_allocation: {...est_allocation, business_tax_cents: null},
        });
        renderPanel();

        await userEvent.click(screen.getByRole('button', {name: 'Steuer berechnen'}));

        const row = screen.getByText('Betriebsanteil').closest('tr');
        expect(row).not.toBeNull();
        expect(within(row as HTMLElement).getByText('—')).toBeVisible();
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
