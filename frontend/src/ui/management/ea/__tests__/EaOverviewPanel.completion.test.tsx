import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { EaSettings, EaSummary } from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaOverviewPanel from '../EaOverviewPanel';

const settings: EaSettings = {
    business_year: 2026,
    default_business_share: 100,
    sv_mode: 'gsvg',
    kleinunternehmer: false,
};

function summary(overrides: Partial<EaSummary> = {}): EaSummary {
    return {
        year: 2026,
        entry_count: 2,
        asset_count: 1,
        income_cents: 100000,
        expense_cents: 10000,
        afa_cents: 5000,
        profit_cents: 85000,
        ...overrides,
    };
}

function renderPanel(value: EaSummary) {
    return renderWithProviders(
        <EaOverviewPanel summary={value} settings={settings} isLoading={false} error={undefined} onRetry={vi.fn()}/>,
    );
}

describe('EaOverviewPanel completion blocks', () => {
    it('renders the monthly social-insurance block with German month names and KV/PV/SVS/UV columns', () => {
        renderPanel(summary({sv_monthly: [
            {month: 1, kv_cents: 10000, pv_cents: 20000, svs_cents: 3000, uv_cents: 1000},
        ]}));

        expect(screen.getByRole('heading', {name: 'Sozialversicherung je Monat'})).toBeVisible();
        const table = screen.getAllByRole('table')[0]!;
        const rows = within(table).getAllByRole('row');
        expect(rows[0]).toHaveTextContent('KV');
        expect(rows[0]).toHaveTextContent('PV');
        expect(rows[0]).toHaveTextContent('SVS');
        expect(rows[0]).toHaveTextContent('UV');
        expect(rows[1]).toHaveTextContent('Jänner');
        expect(rows[1]).toHaveTextContent('100,00 €');
        expect(rows[1]).toHaveTextContent('200,00 €');
    });

    it('renders the "Weitere Versicherungen" list and filters it by SV mode', () => {
        renderPanel(summary({insurance_blocks: [
            {label: 'Krankenversicherung GSVG', monthly_cents: 5000},
            {label: 'Unfallversicherung UV', monthly_cents: 1200},
        ]}));

        expect(screen.getByRole('heading', {name: 'Weitere Versicherungen'})).toBeVisible();
        expect(screen.getByText('Krankenversicherung GSVG')).toBeVisible();
        expect(screen.getByText('Unfallversicherung UV')).toBeVisible();

        const modeSelect = screen.getByRole('combobox', {name: 'SV-Modus'});
        expect(modeSelect).toHaveValue('');
        expect(within(modeSelect).getAllByRole('option')).toHaveLength(5);
    });

    it('renders the AfA card with book value, private share and residual value', () => {
        renderPanel(summary({afa_card: {booking_cents: 10000, private_cents: 2000, residual_cents: 30000}}));

        expect(screen.getByText('Betriebliche AfA')).toBeVisible();
        expect(screen.getByText('Privatanteil')).toBeVisible();
        expect(screen.getByText('Restbuchwert')).toBeVisible();
        expect(screen.getByText(/300,00/)).toBeVisible();
    });

    it('renders the customer/project table and filters its rows by customer', async () => {
        renderPanel(summary({by_customer: [
            {customer: 'Kunde A', project: 'Projekt 1', revenue_cents: 100000, cost_cents: 40000, margin_cents: 60000},
            {customer: 'Kunde B', project: 'Projekt 2', revenue_cents: 50000, cost_cents: 20000, margin_cents: 30000},
        ]}));

        expect(screen.getByRole('heading', {name: 'Kunden und Projekte'})).toBeVisible();
        const table = screen.getAllByRole('table')[0]!;
        expect(within(table).getByText('Kunde A')).toBeVisible();
        expect(within(table).getByText('Kunde B')).toBeVisible();
        expect(within(table).getByText(/600,00/)).toBeVisible();

        await userEvent.selectOptions(screen.getByRole('combobox', {name: 'Kunde'}), 'Kunde A');
        expect(within(table).getByText('Kunde A')).toBeVisible();
        expect(within(table).queryByText('Kunde B')).not.toBeInTheDocument();
    });

    it('shows German empty states when additive fields are missing', () => {
        renderPanel(summary());

        expect(screen.getByText('Für dieses Jahr sind keine monatlichen Sozialversicherungswerte hinterlegt.')).toBeVisible();
        expect(screen.getByText('Keine weiteren Versicherungen für diesen Filter hinterlegt.')).toBeVisible();
        expect(screen.getByText('Für dieses Jahr sind keine AfA-Werte hinterlegt.')).toBeVisible();
        expect(screen.getByText('Keine Kunden- oder Projektdaten für diesen Filter vorhanden.')).toBeVisible();
    });
});
