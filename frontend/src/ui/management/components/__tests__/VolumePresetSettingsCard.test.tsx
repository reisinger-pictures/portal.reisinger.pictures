import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen, waitFor, fireEvent} from '@testing-library/react';
import {renderWithProviders} from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import VolumePresetSettingsCard from '../VolumePresetSettingsCard';
import {useVolumePresets} from '../../../../logic/useVolumePresets';

vi.mock('../../../../logic/useVolumePresets', () => ({
    useVolumePresets: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn(), confirm: vi.fn().mockResolvedValue(true) }),
}));

vi.mock('../../../components/ModalDialogShell', () => ({
    default: ({children, onSubmit}: {children: React.ReactNode; onSubmit: (e: React.FormEvent) => void}) => (
        <form onSubmit={onSubmit}>
            {children}
            <button type="submit">Speichern</button>
        </form>
    ),
}));

function mockPresets(createPreset: ReturnType<typeof vi.fn>) {
    vi.mocked(useVolumePresets).mockReturnValue({
        presets: [],
        isLoading: false,
        createPreset,
        updatePreset: vi.fn().mockResolvedValue(undefined),
        deletePreset: vi.fn().mockResolvedValue(undefined),
        setDefaultPreset: vi.fn().mockResolvedValue(undefined),
    });
}

describe('VolumePresetSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('keeps a partially typed decimal and commits the parsed base price', async () => {
        const user = userEvent.setup();
        const createPreset = vi.fn().mockResolvedValue(undefined);
        mockPresets(createPreset);

        const {container} = renderWithProviders(<VolumePresetSettingsCard />);

        await user.click(screen.getByRole('button', {name: 'Neues Preset'}));
        await user.type(screen.getByPlaceholderText('z.B. Werbung, Standard, Event'), 'Dezimal');

        const basePrice = container.querySelectorAll('input[type="number"]')[0] as HTMLInputElement;

        // Regression: the controlled input used to render (cents / 100).toFixed(2)
        // and parse on every change, so "1.0" was immediately normalised to "1.00".
        fireEvent.change(basePrice, {target: {value: '1.0'}});
        expect(basePrice.value).toBe('1.0');

        fireEvent.change(basePrice, {target: {value: '1.05'}});
        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        await waitFor(() => expect(createPreset).toHaveBeenCalledTimes(1));
        expect(createPreset).toHaveBeenCalledWith({
            name: 'Dezimal',
            tiers: [{min_quantity: 0, price_cents: 105}],
        });
    });

    it('commits decimal tier prices and integer quantities', async () => {
        const user = userEvent.setup();
        const createPreset = vi.fn().mockResolvedValue(undefined);
        mockPresets(createPreset);

        const {container} = renderWithProviders(<VolumePresetSettingsCard />);

        await user.click(screen.getByRole('button', {name: 'Neues Preset'}));
        await user.type(screen.getByPlaceholderText('z.B. Werbung, Standard, Event'), 'Staffel');
        await user.click(screen.getByRole('button', {name: 'Staffel hinzufügen'}));

        const numbers = container.querySelectorAll('input[type="number"]');
        // 0 = base price, 1 = tier min quantity, 2 = tier price
        await user.clear(numbers[1] as HTMLInputElement);
        await user.type(numbers[1] as HTMLInputElement, '5');
        await user.clear(numbers[2] as HTMLInputElement);
        await user.type(numbers[2] as HTMLInputElement, '2.50');

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        await waitFor(() => expect(createPreset).toHaveBeenCalledTimes(1));
        expect(createPreset).toHaveBeenCalledWith({
            name: 'Staffel',
            tiers: [
                {min_quantity: 0, price_cents: 3000},
                {min_quantity: 5, price_cents: 250},
            ],
        });
    });
});
