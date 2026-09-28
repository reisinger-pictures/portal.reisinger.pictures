import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
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
        return renderWithProviders(
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

        // 25 € typed, 2500 cents sent: the form is the boundary where the wire
        // becomes money (owner decision 2026-09-28).
        expect(onSave).toHaveBeenCalledWith(
            expect.objectContaining({
                code: 'TESTCODE',
                type: 'fixed',
                value: 2500,
                scope_type: 'global',
                active: true,
            }),
        );

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    /**
     * The conversion boundary on the write path, and the two branches of the
     * single `value` field in one test: `fixed` is money and is scaled,
     * `percentage` is a percent and must not be. If the percentage branch were
     * scaled too, 10 % would reach the API as 1000 %.
     */
    it('sends a fixed value as cents and a percentage value as percent', async () => {
        const { unmount } = renderDrawer();
        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'FIXEDX');
        const selects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(selects[0], 'fixed');
        const valueInput = screen.getAllByRole('spinbutton').find(
            input => input.getAttribute('step') === '0.01',
        )!;
        await userEvent.clear(valueInput);
        await userEvent.type(valueInput, '15');
        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenLastCalledWith(expect.objectContaining({
            type: 'fixed',
            value: 1500,
        }));
        unmount();

        onSave.mockClear();
        renderDrawer();
        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'PERCENTX');
        const percentSelects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(percentSelects[0], 'percentage');
        const percentInput = screen.getAllByRole('spinbutton').find(
            input => input.getAttribute('step') === '0.01',
        )!;
        await userEvent.clear(percentInput);
        await userEvent.type(percentInput, '15');
        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenLastCalledWith(expect.objectContaining({
            type: 'percentage',
            value: 15,
        }));
    });

    /**
     * The other direction: a stored coupon is re-opened in the unit the admin
     * reads, without drift. A `fixed` value of 1000 cents is 10,00 € in the
     * field — and re-saving it must send 1000 back, not 100000. The
     * `photo_package` sibling is pinned in the same pass because it travels
     * through the identical code path.
     */
    it('loads a stored cents value into the euro field without drift', async () => {
        const { unmount } = renderDrawer({
            id: 1,
            code: 'STORED',
            type: 'fixed',
            value: 1000,
            scope_type: 'global',
            active: true,
            used_count: 0,
        });
        const valueInput = screen.getAllByRole('spinbutton').find(
            input => input.getAttribute('step') === '0.01',
        )!;
        expect(valueInput).toHaveValue(10);
        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenLastCalledWith(expect.objectContaining({value: 1000}));
        unmount();

        onSave.mockClear();
        renderDrawer({
            id: 2,
            code: 'STOREDPCT',
            type: 'percentage',
            value: 15,
            scope_type: 'global',
            active: true,
            used_count: 0,
        });
        const percentInput = screen.getAllByRole('spinbutton').find(
            input => input.getAttribute('step') === '0.01',
        )!;
        expect(percentInput).toHaveValue(15);
    });

    /**
     * `package_price_cents` in, `package_price_cents` out. The field was named
     * cents and the backend multiplied by 100; now the drawer does, so 40,00 €
     * typed is 4000 stored. The round trip below is what a photographer would
     * actually see: re-open the coupon and the price is still 40,00 €.
     */
    it('sends the package price as cents and reads it back as the same euros', async () => {
        const { unmount } = renderDrawer();
        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'PHOTOPKG');
        const selects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(selects[0], 'photo_package');
        await userEvent.selectOptions(selects[1], 'global');
        await userEvent.type(screen.getByPlaceholderText('z.B. 10'), '10');
        await userEvent.type(screen.getByPlaceholderText('z.B. 40'), '40');
        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenLastCalledWith(expect.objectContaining({
            package_quantity: 10,
            package_price_cents: 4000,
        }));
        unmount();

        onSave.mockClear();
        renderDrawer({
            id: 3,
            code: 'PHOTOPKG2',
            type: 'photo_package',
            value: 0,
            package_quantity: 10,
            package_price_cents: 4000,
            scope_type: 'global',
            active: true,
            used_count: 0,
        });
        const priceInput = screen.getByPlaceholderText('z.B. 40');
        expect(priceInput).toHaveValue(40);
        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenLastCalledWith(expect.objectContaining({
            package_price_cents: 4000,
        }));
    });

    it('shows package fields when type=photo_package', async () => {
        renderDrawer();

        const selects = screen.getAllByRole('combobox');
        const typeSelect = selects[0];

        await userEvent.selectOptions(typeSelect, 'photo_package');

        expect(screen.getByPlaceholderText('z.B. 10')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('z.B. 40')).toBeInTheDocument();
        // The plain value field is hidden for photo_package.
        expect(screen.queryByText('Betrag in €')).not.toBeInTheDocument();
    });

    it('keeps value field for fixed and hides package fields', async () => {
        renderDrawer();

        const selects = screen.getAllByRole('combobox');
        const typeSelect = selects[0];

        await userEvent.selectOptions(typeSelect, 'fixed');

        expect(screen.getByText('Betrag in €')).toBeInTheDocument();
        expect(screen.queryByPlaceholderText('z.B. 10')).not.toBeInTheDocument();
    });

    it('requires package fields for photo_package', async () => {
        renderDrawer();

        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'PHOTOPKG');

        const selects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(selects[0], 'photo_package');

        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => {
            expect(onSave).not.toHaveBeenCalled();
            expect(screen.getByText('Anzahl Fotos muss mindestens 1 sein')).toBeInTheDocument();
        });
    });

    it('submits photo_package with package fields', async () => {
        renderDrawer();

        await userEvent.type(screen.getByPlaceholderText('z.B. SOMMER2026'), 'PHOTOPKG');

        const selects = screen.getAllByRole('combobox');
        await userEvent.selectOptions(selects[0], 'photo_package');
        await userEvent.selectOptions(selects[1], 'global');

        const quantityInput = screen.getByPlaceholderText('z.B. 10');
        const priceInput = screen.getByPlaceholderText('z.B. 40');
        await userEvent.type(quantityInput, '10');
        await userEvent.type(priceInput, '40');

        await userEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => {
            expect(onSave).toHaveBeenCalledTimes(1);
        });

        expect(onSave).toHaveBeenCalledWith(
            expect.objectContaining({
                code: 'PHOTOPKG',
                type: 'photo_package',
                package_quantity: 10,
                // 40,00 € typed, 4000 cents sent.
                package_price_cents: 4000,
                scope_type: 'global',
                active: true,
            }),
        );

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
