import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import type { InvoiceDiscount } from '../../api';
import InvoiceDiscountsSection from '../management/components/invoice/InvoiceDiscountsSection';

vi.mock('../components/AutocompleteInput', () => ({
    default: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
        <input
            aria-label="Rabattbeschreibung"
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));

const discounts: InvoiceDiscount[] = [
    { type: 'discount_fixed', description: 'Festpreisrabatt', notes: '', price: 25 },
    { type: 'discount_percent', description: 'Erster Prozentsatz', notes: '', price: 10 },
    { type: 'discount_percent', description: 'Zweiter Prozentsatz', notes: '', price: 20 },
];

function getDisplayedAmounts(): string[] {
    return screen.getAllByText('Gesamt').map((label) => {
        const amountCell = label.closest('.form-control');
        const amount = amountCell?.querySelector('div.text-right');

        if (!amount) {
            throw new Error('Discount amount cell not found.');
        }

        return amount.textContent?.trim() ?? '';
    });
}

function renderSection(subtotal = 200) {
    return renderWithProviders(
        <InvoiceDiscountsSection
            discounts={discounts}
            subtotal={subtotal}
            onDiscountChange={vi.fn()}
            onAddDiscount={vi.fn()}
            onRemoveDiscount={vi.fn()}
        />,
    );
}

describe('InvoiceDiscountsSection', () => {
    it('renders ordered discount amounts for fixed and percentage discounts', () => {
        renderSection();

        expect(getDisplayedAmounts()).toEqual([
            '25.00 €',
            '17.50 €',
            '31.50 €',
        ]);
    });

    it('shows a neutral placeholder while shared pricing input is invalid', () => {
        renderSection(Number.NaN);

        expect(getDisplayedAmounts()).toEqual(['—', '—', '—']);
    });
});
