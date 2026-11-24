import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import type { EurSettings, EurSummary } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurOverviewPanel from '../EurOverviewPanel';

const summary: EurSummary = {
    year: 2026,
    entry_count: 2,
    asset_count: 1,
    income_cents: 100000,
    expense_cents: 10000,
    afa_cents: 5000,
    profit_cents: 85000,
};

function settings(overrides: Partial<EurSettings> = {}): EurSettings {
    return {
        business_year: 2026,
        default_business_share: 100,
        sv_mode: 'gsvg',
        kleinunternehmer: false,
        ...overrides,
    };
}

function renderPanel(value: EurSettings, override: Partial<EurSummary> = {}) {
    return renderWithProviders(
        <EurOverviewPanel summary={{...summary, ...override}} settings={value} isLoading={false} error={undefined} onRetry={vi.fn()}/>,
    );
}

describe('EurOverviewPanel settings labels', () => {
    it('shows the catalogued German Ja/Nein label for Kleinunternehmer', () => {
        renderPanel(settings({kleinunternehmer: true}));

        expect(screen.getByText(/Kleinunternehmer: Ja/)).toBeVisible();
        expect(screen.queryByText(/Kleinunternehmer: true/)).not.toBeInTheDocument();
    });

    it('shows the catalogued German Nein label when Kleinunternehmer is false', () => {
        renderPanel(settings({kleinunternehmer: false}));

        expect(screen.getByText(/Kleinunternehmer: Nein/)).toBeVisible();
        expect(screen.queryByText(/Kleinunternehmer: false/)).not.toBeInTheDocument();
    });

    it('shows the catalogued phrase for uv_only without uppercasing it', () => {
        renderPanel(settings({sv_mode: 'uv_only'}));

        expect(screen.getByText(/Sozialversicherung: nur Unfallversicherung/)).toBeVisible();
        expect(screen.queryByText(/UV_ONLY/)).not.toBeInTheDocument();
    });

    it('shows catalogue-backed uppercase abbreviations for the SV mode codes', () => {
        renderPanel(settings({sv_mode: 'fsvg'}));

        expect(screen.getByText(/Sozialversicherung: FSVG/)).toBeVisible();
    });

    it('reminds of the real 9225 booking when a quarter is open (Portal #58)', () => {
        renderPanel(settings(), {
            sv_reminder: {
                target_per_quarter_cents: 3800,
                quarters: [
                    {quarter: 1, booked_cents: 0, done: false},
                    {quarter: 2, booked_cents: 3800, done: true},
                    {quarter: 3, booked_cents: 0, done: false},
                    {quarter: 4, booked_cents: 0, done: false},
                ],
                open_quarters: [1, 3, 4],
                message: '⚠ Sozialversicherung: noch keine ausreichende 9225-Buchung für Q1, Q3, Q4 2026. Bitte als Ausgabe mit KZ 9225 erfassen.',
            },
        });

        expect(screen.getByRole('status')).toHaveTextContent('Q1, Q3, Q4 2026');
        expect(screen.getByRole('status')).toHaveTextContent('KZ 9225');
    });

    it('shows no reminder when every quarter is booked', () => {
        renderPanel(settings(), {
            sv_reminder: {
                target_per_quarter_cents: 3800,
                quarters: [1, 2, 3, 4].map((quarter) => ({quarter, booked_cents: 3800, done: true})),
                open_quarters: [],
                message: null,
            },
        });

        expect(screen.queryByText(/Sozialversicherung: noch keine/)).not.toBeInTheDocument();
    });
});
