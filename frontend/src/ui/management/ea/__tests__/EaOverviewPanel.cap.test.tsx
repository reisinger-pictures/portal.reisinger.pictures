import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import type { EaSettings, EaSummary } from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaOverviewPanel from '../EaOverviewPanel';

const settings: EaSettings = {
    business_year: 2026,
    default_business_share: 100,
    sv_mode: 'gsvg',
    kleinunternehmer: false,
};

const hint = 'Wow! Maximale Beitragsgrundlage erreicht.';

function monthly(baseAtCap: boolean) {
    // EUE-014: der Hinweis stammt vom bereits gedeckelten SV-Basiswert im Backend.
    return [{
        month: 1,
        kv_cents: 54978,
        pv_cents: 149572,
        svs_cents: 12370,
        uv_cents: 1295,
        base_at_cap_hint: baseAtCap ? hint : null,
    }];
}

function renderPanel(rows: ReturnType<typeof monthly>) {
    const summary: EaSummary = {
        year: 2026,
        entry_count: 2,
        asset_count: 1,
        income_cents: 10000000,
        expense_cents: 0,
        afa_cents: 0,
        profit_cents: 10000000,
        sv_monthly: rows,
    };

    return renderWithProviders(
        <EaOverviewPanel summary={summary} settings={settings} isLoading={false} error={undefined} onRetry={vi.fn()}/>,
    );
}

describe('EUE-014 Hinweis bei maximaler Beitragsgrundlage', () => {
    it('zeigt den gepinnten deutschen Hinweis, wenn die SV-Basis das Maximum erreicht', () => {
        renderPanel(monthly(true));

        expect(screen.getByRole('status')).toHaveTextContent(hint);
    });

    it('zeigt keinen Hinweis unterhalb des Maximums', () => {
        renderPanel(monthly(false));

        expect(screen.queryByText(hint)).not.toBeInTheDocument();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
