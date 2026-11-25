import { describe, expect, it } from 'vitest';
import type { EurAsset } from '../useEur';
import { gwgEligible } from '../eurAssetGwg';

const asset = (overrides: Partial<EurAsset> = {}): EurAsset => ({
    id: 1,
    name: 'Gut',
    invoice_no: 'R-1',
    supplier: 'Händler',
    purchase_date: '2026-01-15',
    cost_cents: 80000,
    useful_life_years: 3,
    business_share: 100,
    method: 'linear',
    degressive_rate: null,
    status: 'active',
    years: [],
    ...overrides,
});

describe('gwgEligible (D-93)', () => {
    const limitCents = 100000;

    it('is true for a business asset below the limit in its acquisition year', () => {
        expect(gwgEligible(asset(), 2026, limitCents)).toBe(true);
    });

    it('is false for a private asset in its acquisition year', () => {
        expect(gwgEligible(asset({scope: 'private', business_share: 30}), 2026, limitCents)).toBe(false);
    });

    it('is false for a legacy private-investment asset', () => {
        expect(gwgEligible(asset({scope: undefined, is_private_investment: true}), 2026, limitCents)).toBe(false);
    });

    it('is false for a business asset in a later year', () => {
        expect(gwgEligible(asset(), 2027, limitCents)).toBe(false);
    });

    it('is false at and above the limit', () => {
        expect(gwgEligible(asset({cost_cents: 100000}), 2026, limitCents)).toBe(false);
        expect(gwgEligible(asset({cost_cents: 120000}), 2026, limitCents)).toBe(false);
    });
});
