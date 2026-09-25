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
});
