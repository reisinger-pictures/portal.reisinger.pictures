import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import type { Product } from '../../../../api';
import ProductBatchTable from '../ProductBatchTable';

/**
 * `Intl` separates the amount from the unit with U+00A0, never with a plain
 * space. `String.fromCharCode` keeps that character visible in the source.
 */
const NBSP = String.fromCharCode(0xa0);

/**
 * The `price` column carries two units depending on the product type, so the
 * rendered value is what proves the type selected the right formatter.
 */
const products: Product[] = [
    // 123456 cents = 1234.56 € — deliberately above 1000 € so the German
    // thousands separator is actually exercised.
    { id: 'p1', type: 'item', name: 'Teilstunde', description: 'Fotoshooting', price: 123456 },
    { id: 'p2', type: 'discount_fixed', name: 'Stammkundenrabatt', description: null, price: 2500 },
    { id: 'p3', type: 'discount_percent', name: 'Aktionsrabatt', description: null, price: 1000 },
];

function renderTable() {
    return renderWithProviders(
        <ProductBatchTable
            title="Leistungen und Produkte"
            products={products}
            onEdit={vi.fn()}
            onDelete={vi.fn()}
            onBatchSave={vi.fn()}
        />,
    );
}

/**
 * Every rendered price cell, keyed off its trailing unit. The table renders a
 * desktop and a mobile copy of each row, so values appear twice; collecting
 * them into a list keeps these assertions independent of that duplication.
 */
function renderedValues(): string[] {
    return screen
        .getAllByText(/€$/)
        .concat(screen.getAllByText(/%$/))
        .map((node) => node.textContent?.trim() ?? '');
}

describe('ProductBatchTable value formatting', () => {
    it('renders euro amounts with German separators and thousands grouping', () => {
        renderTable();

        // Regression: these cells used to read `${(price / 100).toFixed(2)} €`,
        // i.e. "1234.56 €" and "25.00 €" with a period decimal separator.
        expect(renderedValues()).toContain(`1.234,56${NBSP}€`);
        expect(renderedValues()).toContain(`25,00${NBSP}€`);
    });

    it('renders percentage discounts as a percentage, never as an amount', () => {
        renderTable();

        // price 1000 is 10 % in hundredths. A € sign here would be a unit mix-up.
        expect(renderedValues()).toContain(`10,00${NBSP}%`);
        expect(renderedValues()).not.toContain(`10,00${NBSP}€`);
    });

    it('regression: never renders the locale-independent toFixed(2) output', () => {
        renderTable();

        // `${(price / 100).toFixed(2)}` produced exactly these strings. Note the
        // contrast with the German thousands separator in "1.234,56 €", which is
        // a period between digits and therefore must NOT be swept up here.
        const values = renderedValues();
        expect(values).not.toContain('1234.56 €');
        expect(values).not.toContain('25.00 €');
        expect(values).not.toContain('10.00 %');
    });
});
