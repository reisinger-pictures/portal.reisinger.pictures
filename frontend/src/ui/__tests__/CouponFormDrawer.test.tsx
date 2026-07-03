import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import CouponFormDrawer, { type Coupon } from '../management/components/CouponFormDrawer';
import { UIContext } from '../components/UIContext';

import type { UIContextType } from '../components/UIContext';

const mockUIContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

describe('CouponFormDrawer', () => {
    const onClose = vi.fn();
    const onSave = vi.fn().mockResolvedValue(undefined);

    beforeEach(() => {
        vi.clearAllMocks();
    });

    function renderDrawer(editingCoupon: Coupon | null = null) {
        return render(
            <UIContext.Provider value={mockUIContext}>
                <CouponFormDrawer
                    isOpen={true}
                    onClose={onClose}
                    editingCoupon={editingCoupon}
                    onSave={onSave}
                />
            </UIContext.Provider>,
        );
    }

    it('renders create modal with all fields', () => {
        renderDrawer();

        expect(screen.getByText('Neuen Rabattcode anlegen')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('z.B. SOMMER2026')).toBeInTheDocument();
        expect(screen.getAllByRole('combobox')).toHaveLength(2);
        expect(screen.getByRole('button', { name: 'Speichern' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Abbrechen' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Schließen' })).toBeInTheDocument();
    });

    it('shows max_items input when type=percentage', async () => {
        renderDrawer();

        const selects = screen.getAllByRole('combobox');
        const typeSelect = selects[0];

        await userEvent.selectOptions(typeSelect, 'percentage');

        expect(screen.getByPlaceholderText('leer = auf gesamten Warenkorb')).toBeInTheDocument();
    });

    it('hides max_items input when type=fixed', async () => {
        renderDrawer();

        const selects = screen.getAllByRole('combobox');
        const typeSelect = selects[0];

        await userEvent.selectOptions(typeSelect, 'fixed');

        expect(screen.queryByPlaceholderText('leer = auf gesamten Warenkorb')).not.toBeInTheDocument();
    });

    it('shows scope_id input for organisation scope', async () => {
        renderDrawer();

        const selects = screen.getAllByRole('combobox');
        const scopeSelect = selects[1];

        await userEvent.selectOptions(scopeSelect, 'organisation');

        expect(screen.getByPlaceholderText('Galerie-Gruppen-ID')).toBeInTheDocument();
    });

    it('organisation option appears in scope dropdown', () => {
        renderDrawer();

        const scopeSelect = screen.getAllByRole('combobox')[1];
        const options = Array.from(scopeSelect.querySelectorAll('option'));
        const orgOption = options.find(opt => opt.value === 'organisation');

        expect(orgOption).toBeDefined();
        expect(orgOption!.textContent).toBe('Organisation');
    });

    it('submit calls onSave with form data', async () => {
        renderDrawer();

        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'TESTCODE');

        const selects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(selects[0], 'fixed');
        await userEvent.selectOptions(selects[1], 'global');

        const spinbuttons = screen.getAllByRole('spinbutton');
        const valueInput = spinbuttons.find(
            input => input.getAttribute('step') === '0.01',
        );
        expect(valueInput).toBeDefined();
        await userEvent.clear(valueInput!);
        await userEvent.type(valueInput!, '25');

        // Fill optional number fields to prevent NaN validation errors from valueAsNumber
        const maxGlobalInput = spinbuttons.find(
            input => input.getAttribute('name') === 'max_uses_global',
        );
        const maxAccountInput = spinbuttons.find(
            input => input.getAttribute('name') === 'max_uses_per_account',
        );
        expect(maxGlobalInput).toBeDefined();
        expect(maxAccountInput).toBeDefined();
        await userEvent.type(maxGlobalInput!, '100');
        await userEvent.type(maxAccountInput!, '5');

        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => {
            expect(onSave).toHaveBeenCalledTimes(1);
        });

        expect(onSave).toHaveBeenCalledWith(
            expect.objectContaining({
                code: 'TESTCODE',
                type: 'fixed',
                value: 25,
                scope_type: 'global',
                active: true,
            }),
        );

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
