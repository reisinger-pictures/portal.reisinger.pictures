import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../test-setup';
import VolumePresetSettingsCard from '../management/components/VolumePresetSettingsCard';

vi.mock('../../logic/useVolumePresets', () => ({
    useVolumePresets: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useVolumePresets } from '../../logic/useVolumePresets';
import { useUI } from '../components/UIContext';

const mockPresets = [
    {
        id: 1,
        name: 'Standard',
        is_default: true,
        tiers: [
            { position: 0, min_quantity: 0, price_cents: 3000 },
            { position: 1, min_quantity: 10, price_cents: 2500 },
        ],
    },
];

describe('VolumePresetSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useVolumePresets).mockReturnValue({
            presets: mockPresets,
            isLoading: false,
            createPreset: vi.fn(),
            updatePreset: vi.fn(),
            deletePreset: vi.fn(),
            setDefaultPreset: vi.fn(),
        });

        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(() => Promise.resolve(true)),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    /**
     * The currency sign belongs *into* the price field, as its suffix — not as
     * a second element standing next to the input.
     *
     * This is the contract of the preset editor, and it was never pinned: the
     * sign could have been moved back out of the field without a single test
     * noticing, and the audit that reported it as "the sign stands next to the
     * field" was reading a version of this file that no longer exists. The
     * assertion below is what keeps the current state from drifting again.
     *
     * The field frame is the element that carries daisyUI's `input` classes
     * (the input group); the sign is a child of it, so it is inside the field.
     * As a sibling of the input it would sit in a plain wrapper instead.
     */
    it('keeps the currency sign inside the price field', async () => {
        const user = userEvent.setup();
        renderWithProviders(<VolumePresetSettingsCard />);

        await user.click(screen.getByRole('button', { name: 'Neues Preset' }));

        // The base price of the editor: 30,00 € as the machine-readable
        // `30.00` the number input holds.
        const basePrice = screen.getByDisplayValue('30.00');
        const euro = within(screen.getByRole('dialog')).getAllByText('€')[0];

        const field = euro.parentElement as HTMLElement;
        expect(field).toHaveClass('input');
        expect(field).toContainElement(basePrice);
    });

    /**
     * The same contract for the tier rows — they carry their own price field,
     * and a field that is fixed while its sibling is not is exactly the drift
     * this test exists to prevent.
     *
     * The contract is a *membership*, not a count: every price field carries
     * its sign inside its frame, and every sign belongs to a price field. The
     * number of fields is deliberately not pinned — a new price field does
     * not change the contract, and a count would fail the test for a reason
     * that has nothing to do with it.
     */
    it('keeps the currency sign inside the price field of every tier', async () => {
        const user = userEvent.setup();
        renderWithProviders(<VolumePresetSettingsCard />);

        await user.click(screen.getByRole('button', { name: 'Bearbeiten' }));

        const dialog = screen.getByRole('dialog');
        // The price fields are the number inputs with a price step; the
        // quantity field of a tier row has its own step and carries no sign.
        const priceInputs = within(dialog)
            .getAllByRole('spinbutton')
            .filter((input) => input.getAttribute('step') === '0.01');
        const euros = within(dialog).getAllByText('€');

        // Every sign sits inside the frame of a price field …
        for (const euro of euros) {
            const field = euro.parentElement as HTMLElement;
            expect(field).toHaveClass('input');
            expect(field.querySelector('input[type="number"]')).not.toBeNull();
        }

        // … and every price field carries a sign.
        for (const price of priceInputs) {
            const field = price.closest('.input') as HTMLElement;
            expect(field).not.toBeNull();
            expect(within(field).getByText('€')).toBeInTheDocument();
        }
    });
});
