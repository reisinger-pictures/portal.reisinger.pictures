import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
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
};

const brand: BrandSetting = {
    id: 'rp',
    editable_fields: [],
    defaults: effective,
    overrides: {},
    effective,
};

const updateBrandSettings = vi.fn().mockResolvedValue(undefined);

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
});
