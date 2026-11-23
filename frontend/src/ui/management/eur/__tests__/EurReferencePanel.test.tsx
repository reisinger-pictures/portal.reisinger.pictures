import {describe, expect, it, vi} from 'vitest';
import {screen} from '@testing-library/react';
import type {EurReference} from '../../../../logic/useEur';
import {renderWithProviders} from '../../../../test-setup';
import EurReferencePanel from '../EurReferencePanel';

const reference = {
    business_year: 2026,
    expense_kinds: [
        {type: 'Wareneinkäufe', e1a: '9100', share_percent: null},
        {type: 'Rohstoffe', e1a: '9100', share_percent: null},
        {type: 'Fertig- und Einzelteile', e1a: '9100', share_percent: null},
    ],
    income_kinds: [{type: 'Dienstleistung', e1a: '9040', share_percent: null}],
    vat_kinds: [],
    vehicle_types: [],
    payment_methods: [],
    projects: [],
    travel: {countries: [], domestic: {day_cents: 3000, night_cents: 1700}},
    holidays: [],
    income_tax: {
        stages: [],
        tax_free_threshold_cents: 1353900,
        basic_allowance: {limit_cents: 3300000, rate_per_10000: 1500, maximum_cents: 495000},
    },
    social_insurance: {amounts_cents: {}, rates_per_10000: {}},
    usage_counts: {
        expense: {'Wareneinkäufe': 2, 'Rohstoffe': 0, 'Fertig- und Einzelteile': 100},
        income: {'Dienstleistung': 1000},
    },
} as unknown as EurReference;

function renderPanel(data: EurReference = reference) {
    return renderWithProviders(
        <EurReferencePanel reference={data} isLoading={false} error={undefined} onRetry={vi.fn()}/>,
    );
}

describe('EurReferencePanel usage counts (EUE-029)', () => {
    it('shows ❯ for unused kinds, the count for used ones and both capped labels', () => {
        renderPanel();

        expect(screen.getByText('Verwendungszähler')).toBeVisible();
        const unused = screen.getByText('Rohstoffe').closest('li');
        const used = screen.getByText('Wareneinkäufe').closest('li');
        const expenseCap = screen.getByText('Fertig- und Einzelteile').closest('li');
        const incomeCap = screen.getByText('Dienstleistung').closest('li');
        expect(unused?.textContent).toContain('❯');
        expect(used?.textContent).toContain('2');
        expect(expenseCap?.textContent).toContain('99+');
        expect(incomeCap?.textContent).toContain('999+');
    });

    it('omits the usage section for older payloads without usage_counts', () => {
        renderPanel({...reference, usage_counts: undefined});

        expect(screen.queryByText('Verwendungszähler')).not.toBeInTheDocument();
    });
});
