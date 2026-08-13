import {describe, it, expect} from 'vitest';
import {calculateVolumeTier, calculateVolumeTotal, DEFAULT_VOLUME_PRICING, tiersFromApi} from '../useVolumeLicensing';

describe('calculateVolumeTier', () => {
    it('tier 0 for 0 items (edge: empty cart)', () => {
        const result = calculateVolumeTier(0);
        expect(result.tierIndex).toBe(0);
        expect(result.priceCents).toBe(3000);
    });

    it('tier 0 for 1 item', () => {
        const result = calculateVolumeTier(1);
        expect(result.tierIndex).toBe(0);
        expect(result.priceCents).toBe(3000);
        expect(result.label).toContain('30€');
    });

    it('tier 0 for 9 items (upper boundary of tier 0)', () => {
        const result = calculateVolumeTier(9);
        expect(result.tierIndex).toBe(0);
        expect(result.priceCents).toBe(3000);
    });

    it('tier 1 for 10 items (lower boundary)', () => {
        const result = calculateVolumeTier(10);
        expect(result.tierIndex).toBe(1);
        expect(result.priceCents).toBe(2500);
        expect(result.label).toContain('25€');
    });

    it('tier 2 for 20 items (lower boundary of last tier)', () => {
        const result = calculateVolumeTier(20);
        expect(result.tierIndex).toBe(2);
        expect(result.priceCents).toBe(2000);
        expect(result.label).toContain('20€');
        expect(result.isMaxTier).toBe(true);
    });

    it('isMaxTier is false for intermediate tiers', () => {
        expect(calculateVolumeTier(1).isMaxTier).toBe(false);
        expect(calculateVolumeTier(10).isMaxTier).toBe(false);
        expect(calculateVolumeTier(100).isMaxTier).toBe(true);
    });

    it('accepts a custom config with variable tier count', () => {
        const config = {
            tiers: [
                {minQuantity: 0, priceCents: 5000},
                {minQuantity: 5, priceCents: 4000},
                {minQuantity: 15, priceCents: 3000},
            ],
        };
        expect(calculateVolumeTier(3, config).tierIndex).toBe(0);
        expect(calculateVolumeTier(3, config).priceCents).toBe(5000);
        expect(calculateVolumeTier(5, config).tierIndex).toBe(1);
        expect(calculateVolumeTier(5, config).priceCents).toBe(4000);
        expect(calculateVolumeTier(15, config).tierIndex).toBe(2);
        expect(calculateVolumeTier(15, config).priceCents).toBe(3000);
        expect(calculateVolumeTier(15, config).isMaxTier).toBe(true);
    });

    it('supports a single-tier preset (flat price)', () => {
        const config = {tiers: [{minQuantity: 0, priceCents: 5000}]};
        expect(calculateVolumeTier(100, config).priceCents).toBe(5000);
        expect(calculateVolumeTier(100, config).tierIndex).toBe(0);
        expect(calculateVolumeTier(100, config).isMaxTier).toBe(true);
    });
});

describe('calculateVolumeTotal', () => {
    it('returns 0 for an empty array', () => {
        expect(calculateVolumeTotal([])).toBe(0);
    });

    it('calculates total for 3 items at tier 0 (3000 each)', () => {
        const items = [
            {priceCents: 999},
            {price: 999},
            {price: 999},
        ];
        expect(calculateVolumeTotal(items)).toBe(9000);
    });

    it('calculates total for 10 items at tier 1 (2500 each)', () => {
        const items = Array.from({length: 10}, () => ({price: 0}));
        expect(calculateVolumeTotal(items)).toBe(10 * 2500);
    });

    it('calculates total for 20 items at tier 2 (2000 each)', () => {
        const items = Array.from({length: 20}, () => ({price: 0}));
        expect(calculateVolumeTotal(items)).toBe(20 * 2000);
    });

    it('retroactive: all items at same price regardless of stored individual prices', () => {
        const items = [
            {price: 9999},
            {price: 1111},
            {price: 5555},
        ];
        expect(calculateVolumeTotal(items)).toBe(3 * 3000);
    });

    it('works with custom config', () => {
        const customConfig = {
            tiers: [
                {minQuantity: 0, priceCents: 1000},
                {minQuantity: 4, priceCents: 800},
                {minQuantity: 8, priceCents: 500},
            ],
        };
        const items = Array.from({length: 5}, () => ({price: 0}));
        expect(calculateVolumeTotal(items, customConfig)).toBe(5 * 800);
    });

    it('excludes quote items from count for volume tier calculation', () => {
        const items = [
            {price: 1000, isQuote: false},
            {price: 1500, isQuote: false},
            {price: 0, isQuote: true},
        ];
        expect(calculateVolumeTotal(items)).toBe(2 * 3000);
    });

    it('5 non-quote + 3 quote → tier based on 5, total = 5 × tierPrice', () => {
        const items = [
            {price: 1000, isQuote: false},
            {price: 1000, isQuote: false},
            {price: 1000, isQuote: false},
            {price: 1000, isQuote: false},
            {price: 1000, isQuote: false},
            {price: 0, isQuote: true},
            {price: 0, isQuote: true},
            {price: 0, isQuote: true},
        ];
        expect(calculateVolumeTotal(items)).toBe(5 * 3000);
        expect(calculateVolumeTotal(items)).not.toBe(8 * 3000);
    });
});

describe('tiersFromApi', () => {
    it('maps backend payload into config shape sorted by min_quantity', () => {
        const tiers = tiersFromApi([
            {min_quantity: 10, price_cents: 2500},
            {min_quantity: 0, price_cents: 3000},
            {min_quantity: 20, price_cents: 2000},
        ]);
        expect(tiers).toEqual([
            {minQuantity: 0, priceCents: 3000},
            {minQuantity: 10, priceCents: 2500},
            {minQuantity: 20, priceCents: 2000},
        ]);
    });

    it('falls back to DEFAULT_VOLUME_PRICING when payload is empty', () => {
        expect(tiersFromApi(undefined)).toEqual(DEFAULT_VOLUME_PRICING.tiers);
        expect(tiersFromApi([])).toEqual(DEFAULT_VOLUME_PRICING.tiers);
    });
});
