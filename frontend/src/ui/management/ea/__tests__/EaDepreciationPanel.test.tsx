import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createEaAsset, fetchEaComparison, type EaAssetComparison } from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaDepreciationPanel from '../EaDepreciationPanel';

const mutate = vi.fn();

vi.mock('swr', async (importOriginal) => {
    const actual = await importOriginal<typeof import('swr')>();
    return {...actual, useSWRConfig: () => ({mutate})};
});

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {...actual, fetchEaComparison: vi.fn(), createEaAsset: vi.fn()};
});

const comparison: EaAssetComparison = {
    linear: [{year: 2026, method: 'linear', depreciation_cents: 20000, book_value_cents: 180000}],
    degressive: [{year: 2026, method: 'degressiv', depreciation_cents: 60000, book_value_cents: 140000}],
    first_year: {
        linear_cents: 20000,
        degressive_cents: 60000,
        linear_business_cents: 15000,
        degressive_business_cents: 45000,
    },
    suggested_category: {type: 'Laptop', e1a: '9130 - Andere Anlagegüter', share_percent: null},
    switch_recommended_year: 3,
    switch_advantage_cents: 12000,
};

async function fillForm(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText('Bezeichnung'), 'Laptop');
    await user.type(screen.getByLabelText('Rechnungsnummer'), 'R-1');
    await user.type(screen.getByLabelText('Lieferant'), 'Händler');
}

describe('EaDepreciationPanel (#34 P5.2)', () => {
    it('shows both methods side by side with the category suggestion from the catalogue', async () => {
        const user = userEvent.setup();
        renderWithProviders(<EaDepreciationPanel year={2026}/>);
        await fillForm(user);
        vi.mocked(fetchEaComparison).mockResolvedValue(comparison);

        await user.click(screen.getByRole('button', {name: 'Vergleich berechnen'}));

        expect(fetchEaComparison).toHaveBeenCalledWith(expect.objectContaining({
            name: 'Laptop',
            business_share: 100,
        }));
        const table = screen.getByRole('table');
        expect(within(table).getByText(/^200,00/)).toBeVisible();
        expect(within(table).getByText(/^600,00/)).toBeVisible();
        expect(within(table).getByText(/^1\.800,00/)).toBeVisible();
        expect(within(table).getByText(/^1\.400,00/)).toBeVisible();
        expect(within(table).getByText(/^50,00/)).toBeVisible();
        expect(within(table).getAllByText(/^150,00/)).toHaveLength(2);
        expect(screen.getByLabelText(/AfA-Kategorie/)).toHaveValue('Laptop');
        expect(screen.getByText(/9130/)).toBeVisible();
    });

    it('creates the asset with the confirmed category and refreshes the list', async () => {
        const user = userEvent.setup();
        renderWithProviders(<EaDepreciationPanel year={2026}/>);
        await fillForm(user);
        vi.mocked(fetchEaComparison).mockResolvedValue(comparison);
        vi.mocked(createEaAsset).mockResolvedValue({asset: {} as never});

        await user.click(screen.getByRole('button', {name: 'Vergleich berechnen'}));
        await user.clear(screen.getByLabelText(/AfA-Kategorie/));
        await user.type(screen.getByLabelText(/AfA-Kategorie/), 'Andere Anlagegüter');
        await user.click(screen.getByRole('button', {name: 'Linear anlegen'}));

        expect(createEaAsset).toHaveBeenCalledWith(expect.objectContaining({
            name: 'Laptop',
            category: 'Andere Anlagegüter',
            method: 'linear',
            business_share: 100,
        }));
        expect(mutate).toHaveBeenCalledWith('/api/management/ea/assets');
    });

    it('offers the business share only in 5-% steps (P5.4)', () => {
        renderWithProviders(<EaDepreciationPanel year={2026}/>);

        const options = screen.getByLabelText('Betriebsanteil').querySelectorAll('option');
        expect(Array.from(options).map((option) => option.textContent)).toEqual([
            '50 %', '55 %', '60 %', '65 %', '70 %', '75 %', '80 %', '85 %', '90 %', '95 %', '100 %',
        ]);
    });
});
