import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import BrandSettingsCard from '../BrandSettingsCard';
import type { BrandSetting } from '../../../../logic/useBrandSettings';

vi.mock('../../../../logic/useBrandSettings', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useBrandSettings')>();
    return { ...actual, useBrandSettings: vi.fn() };
});

vi.mock('../../../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('swr', () => ({
    useSWRConfig: vi.fn(() => ({ mutate: vi.fn() })),
}));

import { useBrandSettings } from '../../../../logic/useBrandSettings';
import { usePermissions } from '../../../../logic/usePermissions';
import { useUI } from '../../../components/UIContext';

const effective: BrandSetting['effective'] = {
    name: 'Reisinger Pictures',
    portal_name: 'Reisinger Foto Portal',
    impressum_url: 'https://reisinger.pictures/impressum/',
    primary_color: '#1E5631',
    secondary_color: '#A4B494',
    frontend_url: 'https://portal.reisinger.pictures',
    from_address: 'portal@reisinger.pictures',
    from_name: 'Reisinger Foto Portal',
    accounting_email: 'accounting@reisinger.pictures',
    features: { orgs: true },
    pricing_strategy: 'scope_licensing',
};

const brand: BrandSetting = {
    id: 'rp',
    editable_fields: [],
    defaults: effective,
    overrides: {},
    effective,
    pricing_strategy_source: 'default',
};

const updateBrandSettings = vi.fn().mockResolvedValue(undefined);

/** Open the collapsed brand section so the form controls are reachable. */
function openBrandSection(): HTMLElement {
    const card = screen.getByTestId('brand-settings-card');
    const details = card.querySelector('details') as HTMLDetailsElement;
    details.open = true;

    return card;
}

describe('BrandSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useBrandSettings).mockReturnValue({
            brands: [brand],
            isLoading: false,
            error: undefined,
            updateBrandSettings,
        });
        vi.mocked(usePermissions).mockReturnValue({ isSuperAdmin: true } as never);
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('exposes the hydrated Buchhaltungs-E-Mail field by its accessible German label', () => {
        vi.mocked(useBrandSettings).mockReturnValue({
            brands: undefined,
            isLoading: false,
            error: undefined,
            updateBrandSettings,
        });

        const { rerender } = renderWithProviders(<BrandSettingsCard />);
        expect(screen.queryByRole('textbox', { name: 'Buchhaltungs-E-Mail' })).not.toBeInTheDocument();

        vi.mocked(useBrandSettings).mockReturnValue({
            brands: [brand],
            isLoading: false,
            error: undefined,
            updateBrandSettings,
        });
        rerender(<BrandSettingsCard />);

        const card = screen.getByTestId('brand-settings-card');
        const details = card.querySelector('details') as HTMLDetailsElement;
        details.open = true;

        const input = within(card).getByRole('textbox', {
            name: 'Buchhaltungs-E-Mail',
        });
        expect(input).toHaveValue(effective.accounting_email);
        expect(screen.getByLabelText('Buchhaltungs-E-Mail', { exact: true })).toBe(input);
    });

    it('renders the Preisstrategie select with the three options', () => {
        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        const select = within(card).getByRole('combobox', { name: 'Preisstrategie' });
        const options = within(select).getAllByRole('option');

        expect(options.map((option) => (option as HTMLOptionElement).value)).toEqual([
            '',
            'scope_licensing',
            'volume_licensing',
        ]);
        expect(options.map((option) => option.textContent)).toEqual([
            'Standard (DB-Einstellung)',
            'Scope-Lizenzierung',
            'Mengen-Lizenzierung',
        ]);
        // No overlay is persisted — the form must not offer the effective mode.
        expect(select).toHaveValue('');
    });

    it('sends the dirty pricing strategy on save', async () => {
        const user = userEvent.setup();
        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        const select = within(card).getByRole('combobox', { name: 'Preisstrategie' });
        await user.selectOptions(select, 'volume_licensing');
        await user.click(within(card).getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(updateBrandSettings).toHaveBeenCalledTimes(1));
        expect(updateBrandSettings).toHaveBeenCalledWith('rp', { pricing_strategy: 'volume_licensing' });
    });

    it('sends null when the pricing strategy is reset to the standard', async () => {
        const user = userEvent.setup();
        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        await user.click(within(card).getByRole('button', { name: 'Auf Standard zurücksetzen' }));

        await waitFor(() => expect(updateBrandSettings).toHaveBeenCalledTimes(1));
        expect(updateBrandSettings).toHaveBeenCalledWith(
            'rp',
            expect.objectContaining({ pricing_strategy: null })
        );
    });

    it('shows the standard again after a reset and does not re-send the strategy with the next save', async () => {
        const user = userEvent.setup();
        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        const select = within(card).getByRole('combobox', { name: 'Preisstrategie' });
        await user.selectOptions(select, 'volume_licensing');
        await user.click(within(card).getByRole('button', { name: 'Auf Standard zurücksetzen' }));

        await waitFor(() => expect(updateBrandSettings).toHaveBeenCalledTimes(1));

        // The overlay row is gone — the select must not keep showing it.
        expect(select).toHaveValue('');

        // A later save of another field must not re-create the deleted overlay.
        const nameInput = within(card).getByPlaceholderText('Reisinger Pictures');
        await user.clear(nameInput);
        await user.type(nameInput, 'Neuer Markenname');
        await user.click(within(card).getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(updateBrandSettings).toHaveBeenCalledTimes(2));
        const [, payload] = updateBrandSettings.mock.calls[1];
        expect(payload).not.toHaveProperty('pricing_strategy');
        expect(payload).toEqual({ name: 'Neuer Markenname' });
    });

    it('sends no pricing strategy when only another field changed', async () => {
        const user = userEvent.setup();
        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        const nameInput = within(card).getByPlaceholderText('Reisinger Pictures');
        await user.clear(nameInput);
        await user.type(nameInput, 'Neuer Markenname');
        await user.click(within(card).getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(updateBrandSettings).toHaveBeenCalledTimes(1));
        const [, payload] = updateBrandSettings.mock.calls[0];
        expect(payload).not.toHaveProperty('pricing_strategy');
        expect(payload).toEqual({ name: 'Neuer Markenname' });
    });

    it('shows the effective mode and its source as a hint', () => {
        vi.mocked(useBrandSettings).mockReturnValue({
            brands: [
                {
                    ...brand,
                    effective: { ...effective, pricing_strategy: 'volume_licensing' },
                    pricing_strategy_source: 'setting',
                },
            ],
            isLoading: false,
            error: undefined,
            updateBrandSettings,
        });

        renderWithProviders(<BrandSettingsCard />);

        expect(screen.getByText('Aktiv: Mengen-Lizenzierung (DB-Einstellung)')).toBeInTheDocument();
    });

    it('shows the brand overlay as the source of the effective mode', () => {
        vi.mocked(useBrandSettings).mockReturnValue({
            brands: [
                {
                    ...brand,
                    overrides: { pricing_strategy: 'volume_licensing' },
                    effective: { ...effective, pricing_strategy: 'volume_licensing' },
                    pricing_strategy_source: 'overlay',
                },
            ],
            isLoading: false,
            error: undefined,
            updateBrandSettings,
        });

        renderWithProviders(<BrandSettingsCard />);
        const card = openBrandSection();

        expect(screen.getByText('Aktiv: Mengen-Lizenzierung (Brand-Overlay)')).toBeInTheDocument();
        // The persisted overlay — not the effective value — hydrates the select.
        expect(within(card).getByRole('combobox', { name: 'Preisstrategie' })).toHaveValue('volume_licensing');
    });
});
