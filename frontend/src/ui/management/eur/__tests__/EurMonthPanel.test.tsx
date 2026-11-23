import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { useEurMonth, type EurMonthData } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurMonthPanel from '../EurMonthPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, useEurMonth: vi.fn(), closeEurMonth: vi.fn()};
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

/** Mirrors the complete SV key set returned by the month endpoint (YearTables). */
const monthData: EurMonthData = {
    year: 2026,
    month: 5,
    lines: [],
    totals: {income_cents: 0, expense_cents: 0, warehouse_cents: 0, vat_cents: 0, hours_minutes: 0},
    checks: [],
    social_insurance: {
        amounts_cents: {
            max_bgl: 808500,
            kv_min: 3747,
            gsvg_pv_min: 10195,
            fsvg_pv_min: 11022,
            gsvg_ns_pv_min: 10195,
            uv_pauschale: 1295,
        },
        rates_per_10000: {
            kv_satz: 680,
            gsvg_pv_satz: 1850,
            fsvg_pv_satz: 2000,
            svs_satz: 153,
        },
    },
    income_tax: {profit_cents: 85000, tax_cents: 0},
    report: {status: 'open', closed_at: null},
    signature: {status: 'none', provider: null, is_test: false},
};

const amountLabels = [
    'Maximale Beitragsgrundlage',
    'Krankenversicherung Mindestbeitrag',
    'Mindestbeitrag Pensionsversicherung GSVG',
    'Mindestbeitrag Pensionsversicherung FSVG',
    'Mindestbeitrag Pensionsversicherung GSVG (Neue Selbständige)',
    'Unfallversicherung Pauschale',
];

const rateLabels = [
    'Krankenversicherungssatz',
    'Pensionsversicherungssatz GSVG',
    'Pensionsversicherungssatz FSVG',
    'Satz der Selbständigenvorsorge',
];

const rawKeys = [
    'max_bgl', 'kv_min', 'gsvg_pv_min', 'fsvg_pv_min', 'gsvg_ns_pv_min', 'uv_pauschale',
    'kv_satz', 'gsvg_pv_satz', 'fsvg_pv_satz', 'svs_satz',
];

function renderPanel(data: EurMonthData = monthData) {
    vi.mocked(useEurMonth).mockReturnValue(swrResult<ReturnType<typeof useEurMonth>>(data));
    return renderWithProviders(<EurMonthPanel year={2026} month={5} onMonthChange={vi.fn()}/>);
}

describe('EurMonthPanel social-insurance labels', () => {
    it('shows catalogued German labels instead of raw amount and rate keys', () => {
        renderPanel();

        for (const label of [...amountLabels, ...rateLabels]) {
            expect(screen.getByText(label)).toBeVisible();
        }
        for (const key of rawKeys) {
            expect(screen.queryByText(key)).not.toBeInTheDocument();
        }
    });

    it('uses the generic catalogued label for an unknown key', () => {
        renderPanel({
            ...monthData,
            social_insurance: {
                amounts_cents: {some_new_key: 100},
                rates_per_10000: {brand_new_rate: 100},
            },
        });

        expect(screen.getAllByText('Sonstiger Wert')).toHaveLength(2);
        expect(screen.queryByText('some_new_key')).not.toBeInTheDocument();
        expect(screen.queryByText('brand_new_rate')).not.toBeInTheDocument();
    });
});

describe('EurMonthPanel status values', () => {
    it('translates the default open/none statuses to German', () => {
        renderPanel();

        expect(screen.getByText(/Status: offen · Signatur: keine/)).toBeVisible();
        expect(screen.queryByText('Status: open · Signatur: none')).not.toBeInTheDocument();
        expect(screen.queryByText(/\bopen\b/)).not.toBeInTheDocument();
        expect(screen.queryByText(/\bnone\b/)).not.toBeInTheDocument();
    });

    it('translates closed/signed statuses to German', () => {
        renderPanel({
            ...monthData,
            report: {status: 'closed', closed_at: '2026-05-31T00:00:00+02:00'},
            signature: {status: 'signed', provider: 'Test', is_test: true},
        });

        expect(screen.getByText(/Status: abgeschlossen · Signatur: vorhanden/)).toBeVisible();
        expect(screen.queryByText(/\bclosed\b/)).not.toBeInTheDocument();
        expect(screen.queryByText(/\bsigned\b/)).not.toBeInTheDocument();
    });

    it.each([
        ['pending', 'ausstehend'],
        ['failed', 'fehlgeschlagen'],
    ])('translates signature status %s to the German label %s', (status, expected) => {
        renderPanel({...monthData, signature: {...monthData.signature, status}});

        expect(screen.getByText(new RegExp(`Signatur: ${expected}`))).toBeVisible();
        expect(screen.queryByText(new RegExp(`\\b${status}\\b`))).not.toBeInTheDocument();
    });

    it('rendert den Kopfhinweis aus der API (EUE-116)', () => {
        const hint = '💡 Rechenaufwendige Kalkulation: Bitte nach Verwendung wieder auf OFF setzten!';
        renderPanel({...monthData, hint});

        expect(screen.getByText(hint)).toBeVisible();
    });

    it('lässt den Kopfhinweis weg, wenn die API ihn nicht liefert (EUE-116)', () => {
        renderPanel();

        expect(screen.queryByText(/Rechenaufwendige Kalkulation/)).not.toBeInTheDocument();
    });
});
