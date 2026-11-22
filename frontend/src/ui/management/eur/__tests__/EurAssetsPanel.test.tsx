import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    bookEurAssetDepreciation,
    switchEurAssetToLinear,
    useEurAssets,
    type EurAsset,
    type EurPlanYear,
} from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurAssetsPanel from '../EurAssetsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurAssets: vi.fn(), bookEurAssetDepreciation: vi.fn(), switchEurAssetToLinear: vi.fn()};
});

const plan = (overrides: Partial<EurPlanYear> = {}): EurPlanYear => ({
    year: 2026,
    method: 'linear',
    depreciation_cents: 20000,
    book_value_cents: 180000,
    private_use_percent: 0,
    private_use_cents: 0,
    business_use_cents: 20000,
    status: 'open',
    eur_entry_id: null,
    ...overrides,
});

const asset = (overrides: Partial<EurAsset> = {}): EurAsset => ({
    id: 1,
    name: 'Kamera',
    invoice_no: 'R-1',
    supplier: 'Händler',
    purchase_date: '2026-01-15',
    cost_cents: 200000,
    useful_life_years: 10,
    business_share: 100,
    method: 'linear',
    degressive_rate: null,
    status: 'active',
    is_private_investment: false,
    years: [plan()],
    ...overrides,
});

function swrResult(data: unknown) {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as ReturnType<typeof useEurAssets>;
}

function setAssets(...assets: EurAsset[]) {
    vi.mocked(useEurAssets).mockReturnValue(swrResult({assets}));
}

describe('EurAssetsPanel (#34)', () => {
    it('shows the depreciation directly and books an open year', async () => {
        const user = userEvent.setup();
        setAssets(asset());
        vi.mocked(bookEurAssetDepreciation).mockResolvedValue({
            entry: {id: 9, receipt_no: 'AV-1-2026', business_amount_cents: 20000},
            already_booked: false,
        });

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        // AfA and betrieblicher Anteil both render the booked 200,00 €; the
        // acquisition column shows 2.000,00 € (cost is stored in cents).
        expect(within(table).getAllByText(/200,00/)).toHaveLength(2);
        expect(within(table).getByText(/2\.000,00/)).toBeVisible();
        expect(within(table).getByText(/10,00/)).toBeVisible();
        expect(within(table).getByText(/^0,00/)).toBeVisible();
        expect(within(table).getByText('Offen')).toBeVisible();

        await user.click(within(table).getByRole('button', {name: 'AfA buchen'}));

        expect(bookEurAssetDepreciation).toHaveBeenCalledWith(1, 2026);
        expect(screen.getByRole('status')).toHaveTextContent(/AV-1-2026/);
    });

    it('filters private investments (P5.4)', async () => {
        const user = userEvent.setup();
        setAssets(
            asset({id: 1, name: 'Privat-Notebook', is_private_investment: true}),
            asset({id: 2, name: 'Büro-PC', is_private_investment: false}),
        );

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        expect(screen.getByText('Privat-Notebook')).toBeVisible();
        expect(screen.getByText('Büro-PC')).toBeVisible();

        await user.click(screen.getByRole('button', {name: 'Privateinlagen'}));

        expect(screen.getByText('Privat-Notebook')).toBeVisible();
        expect(screen.queryByText('Büro-PC')).not.toBeInTheDocument();
    });

    it('hides the switch after a permanent move to linear (P5.3)', () => {
        setAssets(asset({
            method: 'degressiv',
            degressive_rate: 3000,
            switched_to_linear_at: '2026-06-01T00:00:00Z',
        }));

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        expect(screen.getByText('Linear (fest)')).toBeVisible();
        expect(screen.queryByRole('button', {name: 'Zu linear wechseln'})).not.toBeInTheDocument();
    });

    it('shows a withdrawal hint for any mixed private share (EUE-055)', () => {
        // 75 % business / 25 % private is backend-producible (50–100 in
        // 5-% steps) and triggers EUE-055 for any private share above zero.
        setAssets(asset({business_share: 75, years: [plan({
            private_use_percent: 25,
            private_use_cents: 5000,
            business_use_cents: 15000,
        })]}));

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        expect(screen.getByText('Entnahme prüfen')).toBeVisible();
    });

    it('requires a reason for the one-time switch to linear (P5.3)', async () => {
        const user = userEvent.setup();
        setAssets(asset({method: 'degressiv', degressive_rate: 3000}));
        vi.mocked(switchEurAssetToLinear).mockResolvedValue({asset: asset()});

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        await user.click(screen.getByRole('button', {name: 'Zu linear wechseln'}));
        const input = screen.getByRole('textbox');
        await user.type(input, 'Wirtschaftlichkeitsprüfung 2026');
        await user.click(screen.getByRole('button', {name: 'Bestätigen'}));

        expect(switchEurAssetToLinear).toHaveBeenCalledWith(1, 2026, 'Wirtschaftlichkeitsprüfung 2026');
    });
});
