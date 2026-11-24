import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { useEurAusfuellhilfe, type EurAusfuellhilfe } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurAusfuellhilfePanel from '../EurAusfuellhilfePanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurAusfuellhilfe: vi.fn()};
});

const payload: EurAusfuellhilfe = {
    year: 2026,
    units: {money: 'cents', time: 'minutes', rate: 'per-10000'},
    notice: 'D-91: Die Steuererklärung wird manuell in FinanzOnline eingetragen; das Portal liefert Werte, keine elektronische Übermittlung.',
    empty_is_null: true,
    sections: [
        {
            id: 'e1a',
            title_de: 'E1A-Kennzahlen',
            fields: [
                {
                    order: 2,
                    sheet: 'E1A',
                    cell: 'C40',
                    kz: 'E40',
                    label_de: 'Arbeitsplatzpauschale',
                    value_cents: 40000,
                    value_text: null,
                    unit: 'cents',
                    source: 'EurTaxStatementService',
                    deviations: [{code: 'D-79', text_de: 'Das Portal verwendet den FSVG-Mindestsockel.'}],
                },
                {
                    order: 1,
                    sheet: 'E1A',
                    cell: 'C39',
                    kz: '9216',
                    label_de: 'Ergonomisches Mobiliar',
                    value_cents: null,
                    value_text: null,
                    unit: 'cents',
                    source: 'EurTaxStatementService',
                    deviations: [],
                },
                {
                    order: 3,
                    sheet: 'Ü',
                    cell: 'U11',
                    kz: '245',
                    label_de: 'Nichtselbständige Einkünfte',
                    value_cents: null,
                    value_text: '8:30',
                    unit: 'minutes',
                    source: 'EurHoursService',
                    deviations: [],
                },
            ],
        },
    ],
};

function swrResult(data: unknown, overrides: {error?: unknown; isLoading?: boolean} = {}) {
    return {data, error: undefined, isLoading: false, isValidating: false, mutate: vi.fn(), ...overrides};
}

function mockPayload(value: unknown) {
    vi.mocked(useEurAusfuellhilfe).mockReturnValue(
        swrResult(value) as unknown as ReturnType<typeof useEurAusfuellhilfe>,
    );
}

describe('EurAusfuellhilfePanel', () => {
    it('shows the D-91 boundary notice and a downloadable PDF link', () => {
        mockPayload(payload);
        renderWithProviders(<EurAusfuellhilfePanel year={2026}/>);

        expect(screen.getByRole('status')).toHaveTextContent('D-91: Die Steuererklärung wird manuell in FinanzOnline eingetragen');
        const link = screen.getByRole('link', {name: 'Ausfüllhilfe als PDF herunterladen'});
        expect(link).toHaveAttribute('href', '/api/management/eur/pdf/ausfuellhilfe?year=2026');
        expect(link).toHaveAttribute('download');
    });

    it('renders fields in ODS order with KZ badges, Euro values and empty values', () => {
        mockPayload(payload);
        renderWithProviders(<EurAusfuellhilfePanel year={2026}/>);

        const section = screen.getByRole('heading', {name: 'E1A-Kennzahlen'}).closest('section');
        expect(section).not.toBeNull();
        const rows = within(section as HTMLElement).getAllByRole('row');
        expect(rows[1]).toHaveTextContent('Ergonomisches Mobiliar');
        expect(rows[1]).toHaveTextContent('—');
        expect(rows[2]).toHaveTextContent('Arbeitsplatzpauschale');
        expect(rows[2]).toHaveTextContent('400,00');
        expect(rows[3]).toHaveTextContent('Nichtselbständige Einkünfte');
        expect(rows[3]).toHaveTextContent('8:30 Min.');

        expect(within(rows[1]).getByText('9216')).toHaveClass('badge', 'badge-outline');
        expect(within(rows[2]).getByText('E1A!C40')).toBeVisible();
    });

    it('marks deviations with a warning code and explains them in German', () => {
        mockPayload(payload);
        renderWithProviders(<EurAusfuellhilfePanel year={2026}/>);

        const summary = screen.getByText(/⚠ D-79/);
        expect(summary).toHaveAttribute('title', 'Das Portal verwendet den FSVG-Mindestsockel.');
        expect(summary.closest('details')).toHaveTextContent('Das Portal verwendet den FSVG-Mindestsockel.');
    });

    it('reports a load error instead of showing an empty table', () => {
        vi.mocked(useEurAusfuellhilfe).mockReturnValue(
            swrResult(undefined, {error: new Error('offline')}) as unknown as ReturnType<typeof useEurAusfuellhilfe>,
        );
        renderWithProviders(<EurAusfuellhilfePanel year={2026}/>);

        expect(screen.getByRole('alert')).toHaveTextContent('Die Ausfüllhilfe konnte nicht geladen werden.');
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});
