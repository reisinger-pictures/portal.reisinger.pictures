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

/**
 * `Intl` separates the amount from the symbol with U+00A0, never with a plain
 * space — spelled out so an expectation cannot be satisfied by a normal space.
 * `getDisplayedAmounts` reads raw `textContent`, which testing-library does not
 * normalise. `String.fromCharCode` keeps the character visible in the source
 * instead of hiding it behind an escape that looks like a normal space.
 */
const NBSP = String.fromCharCode(0xa0);

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

        // German notation, produced by formatEuro: comma decimal separator plus a
        // non-breaking space before the symbol. This used to read
        // ['25.00 €', '17.50 €', '31.50 €'] because the component used
        // `amount.toFixed(2)`, which is locale-independent and always emits a period.
        expect(getDisplayedAmounts()).toEqual([
            `25,00${NBSP}€`,
            `17,50${NBSP}€`,
            `31,50${NBSP}€`,
        ]);
    });

    it('shows a neutral placeholder while shared pricing input is invalid', () => {
        renderSection(Number.NaN);

        expect(getDisplayedAmounts()).toEqual(['—', '—', '—']);
    });
});
