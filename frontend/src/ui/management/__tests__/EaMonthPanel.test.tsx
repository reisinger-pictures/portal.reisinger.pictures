import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { useEaMonth, type EaMonthData } from '../../../logic/useEa';
import { renderWithProviders } from '../../../test-setup';
import EaMonthPanel from '../ea/EaMonthPanel';

vi.mock('../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useEa')>();
    return {...actual, useEaMonth: vi.fn(), closeEaMonth: vi.fn()};
});

/** Simuliert die Antwort mit Check-Objekten, wie sie Issue #52 ausgelöst hat. */
function monthData(warnings: unknown, hints: unknown): EaMonthData {
    return {
        year: 2026,
        month: 5,
        lines: [{
            type: 'A', day: null, description: 'Abo ohne Tag', amount_cents: 1000,
            business_amount_cents: 1000, vat_kind: null, receipt_no: null,
            warnings, hints,
        }] as unknown as EaMonthData['lines'],
        totals: {income_cents: 0, expense_cents: 1000, warehouse_cents: 0, vat_cents: 0, hours_minutes: 0},
        checks: [],
        social_insurance: {amounts_cents: {}, rates_per_10000: {}},
        income_tax: {profit_cents: -1000, tax_cents: 0},
        report: {status: 'open', closed_at: null},
        signature: {status: 'none', provider: null, is_test: false},
    };
}

function renderPanel(data: EaMonthData) {
    vi.mocked(useEaMonth).mockReturnValue({
        data, error: undefined, isLoading: false, mutate: vi.fn(),
    } as unknown as ReturnType<typeof useEaMonth>);

    return renderWithProviders(<EaMonthPanel year={2026} month={5} onMonthChange={vi.fn()}/>);
}

describe('EaMonthPanel · Zeilenhinweise', () => {
    it('zeigt Check-Objekte als Nachricht und Hinweis, statt abzustürzen', () => {
        const check = {
            code: 'DAY',
            ok: false,
            message: '⚠ Tag fehlt.',
            hint: '💡 Bitte den Tag der Buchung (1–31) ergänzen.',
        };
        const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        try {
            renderPanel(monthData([check], [check]));

            expect(screen.getByRole('heading', {name: 'Monatsabschluss'})).toBeVisible();
            expect(screen.getByText('⚠ Tag fehlt.')).toBeVisible();
            expect(screen.getByText('💡 Bitte den Tag der Buchung (1–31) ergänzen.')).toBeVisible();
            expect(errorSpy).not.toHaveBeenCalled();
        } finally {
            errorSpy.mockRestore();
        }
    });

    it('behält die String-Darstellung und ergänzt die Symbole', () => {
        renderPanel(monthData(['Tag fehlt.'], ['Bitte den Tag ergänzen.']));

        expect(screen.getByRole('heading', {name: 'Monatsabschluss'})).toBeVisible();
        expect(screen.getByText('⚠ Tag fehlt.')).toBeVisible();
        expect(screen.getByText('💡 Bitte den Tag ergänzen.')).toBeVisible();
    });
});
