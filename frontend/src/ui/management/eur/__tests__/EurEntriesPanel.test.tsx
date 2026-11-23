import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import type { EurEntriesResponse } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurEntriesPanel from '../EurEntriesPanel';

const data: EurEntriesResponse = {
    entries: [
        {
            id: 1, year: 2026, month: 5, direction: 'expense', kind_type: 'Software',
            e1a_code: '9190', business_share: 60, amount_cents: 10000, business_amount_cents: 6000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
            checks: [{code: 'BE-ART', ok: false, message: 'Beleg fehlt', hint: 'Ergänzen Sie den Beleg im Tab Belege.'}],
        },
        {
            id: 2, year: 2026, month: 6, direction: 'income', kind_type: 'Dienstleistung',
            e1a_code: '9040', business_share: 100, amount_cents: 25000, business_amount_cents: 25000,
            vat_kind: null, payment_method: null, project: null, customer: null, receipt_no: null, note: null,
            checks: [],
        },
    ],
    totals: {income_cents: 25000, expense_cents: 10000},
};

function renderPanel() {
    return renderWithProviders(
        <EurEntriesPanel
            data={data}
            isLoading={false}
            error={undefined}
            onRetry={() => {}}
            onCreate={() => {}}
        />,
    );
}

describe('EurEntriesPanel', () => {
    it('renders one mobile card per entry with the same values and labels as the table', () => {
        renderPanel();

        expect(document.getElementById('eur-buchungen')).toBeInTheDocument();
        const cards = screen.getAllByRole('article');
        expect(cards).toHaveLength(2);

        const first = within(cards[0]!);
        expect(first.getByRole('heading', {name: 'Mai'})).toBeVisible();
        expect(first.getByText('Ausgabe')).toBeVisible();
        expect(first.getByText('Software')).toBeVisible();
        expect(first.getByText(/100,00/)).toBeVisible();
        expect(first.getByText('60 %')).toBeVisible();
        expect(first.getByText(/60,00/)).toBeVisible();
        expect(first.getByText('Beleg fehlt')).toBeVisible();

        const second = within(cards[1]!);
        expect(second.getByRole('heading', {name: 'Juni'})).toBeVisible();
        expect(second.getByText('Einnahme')).toBeVisible();
        expect(second.getByText('Dienstleistung')).toBeVisible();

        const table = within(screen.getByRole('table'));
        expect(table.getByText('Mai')).toBeVisible();
        expect(table.getByText('Juni')).toBeVisible();
        expect(table.getByText('60 %')).toBeVisible();
        expect(table.getByText(/100,00/)).toBeVisible();
        expect(table.getByText('Beleg fehlt')).toBeVisible();
    });

    it('shows the cards only on mobile and the table only from md upwards', () => {
        const {container} = renderPanel();

        const mobile = container.querySelector('.md\\:hidden');
        const desktop = container.querySelector('.hidden.md\\:block');
        expect(mobile).not.toBeNull();
        expect(desktop).not.toBeNull();
        expect(mobile).toContainElement(screen.getAllByRole('article')[0]!);
        expect(desktop).toContainElement(screen.getByRole('table'));
    });

    it('keeps the loading and error states unchanged', () => {
        const {unmount} = renderWithProviders(
            <EurEntriesPanel data={undefined} isLoading error={undefined} onRetry={() => {}} onCreate={() => {}} />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('Buchungen werden geladen …');
        unmount();

        renderWithProviders(
            <EurEntriesPanel data={undefined} isLoading={false} error={new Error('boom')}
                onRetry={vi.fn()} onCreate={() => {}} />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Die Buchungen konnten nicht geladen werden.');
    });
});
