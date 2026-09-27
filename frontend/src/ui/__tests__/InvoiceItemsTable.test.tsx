import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import InvoiceItemsTable from '../management/components/invoice/InvoiceItemsTable';
import { renderWithProviders } from '../../test-setup';
import type { InvoiceItem } from '../../api';

const item: InvoiceItem = {
    type: 'item',
    description: 'Teilstunde',
    notes: '',
    qty: 0.25,
    price: 10,
};

/**
 * `Intl` separates the amount from the symbol with U+00A0, never with a plain
 * space. `String.fromCharCode` keeps that character visible in the source.
 */
const NBSP = String.fromCharCode(0xa0);

/** The read-only "Gesamt" total, i.e. the cell that a human reads. */
function getRowTotal(): string {
    const label = screen.getByText('Gesamt');
    const cell = label.closest('.form-control')?.querySelector('div.text-right');
    if (!cell) throw new Error('Item total cell not found.');
    return cell.textContent?.trim() ?? '';
}

function renderTable(quantityMode?: 'manual' | 'contract') {
    return renderWithProviders(
        <InvoiceItemsTable
            items={[item]}
            quantityMode={quantityMode}
            onItemChange={vi.fn()}
            onAddItem={vi.fn()}
            onRemoveItem={vi.fn()}
            onMoveItemUp={vi.fn()}
            onMoveItemDown={vi.fn()}
        />,
    );
}

describe('InvoiceItemsTable quantity modes', () => {
    it('keeps fractional quarter-step quantities for manual invoices', () => {
        renderTable();

        const quantity = screen.getAllByRole('spinbutton')[0];
        expect(quantity).toHaveAttribute('min', '0.25');
        expect(quantity).toHaveAttribute('step', '0.25');
        expect(quantity).toHaveValue(0.25);
    });

    it('uses whole-unit controls for contract snapshots', () => {
        renderTable('contract');

        const quantity = screen.getAllByRole('spinbutton')[0];
        expect(quantity).toHaveAttribute('min', '1');
        expect(quantity).toHaveAttribute('step', '1');
    });

    it('renders the row total in German notation', () => {
        renderTable();

        // 0.25 qty × 10.00 = 2.50. This used to read "2.50 €", because the cell
        // used `.toFixed(2)`, which is locale-independent and always emits a period.
        expect(getRowTotal()).toBe(`2,50${NBSP}€`);
    });
});
