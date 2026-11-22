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

function renderPanel(value: EurSettings) {
    return renderWithProviders(
        <EurOverviewPanel summary={summary} settings={value} isLoading={false} error={undefined} onRetry={vi.fn()}/>,
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
});
