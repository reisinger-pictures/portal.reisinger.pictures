import {describe, it, expect} from 'vitest';
import {calculateVolumeTier, calculateVolumeTotal, DEFAULT_VOLUME_PRICING} from '../useVolumeLicensing';

describe('calculateVolumeTier', () => {
    it('tier 1 for 0 items (edge: empty cart)', () => {
        const result = calculateVolumeTier(0);
        expect(result.tier).toBe(1);
        expect(result.priceCents).toBe(3000);
    });

    it('tier 1 for 1 item', () => {
        const result = calculateVolumeTier(1);
        expect(result.tier).toBe(1);
        expect(result.priceCents).toBe(3000);
        expect(result.label).toContain('30€');
    });

    it('tier 1 for 9 items (upper boundary of tier 1)', () => {
        const result = calculateVolumeTier(9);
        expect(result.tier).toBe(1);
        expect(result.priceCents).toBe(3000);
    });

    it('tier 2 for 10 items (lower boundary of tier 2)', () => {
        const result = calculateVolumeTier(10);
        expect(result.tier).toBe(2);
        expect(result.priceCents).toBe(2500);
        expect(result.label).toContain('25€');
    });

    it('tier 2 for 11 items', () => {
        const result = calculateVolumeTier(11);
        expect(result.tier).toBe(2);
        expect(result.priceCents).toBe(2500);
    });

    it('tier 2 for 19 items (upper boundary of tier 2)', () => {
        const result = calculateVolumeTier(19);
        expect(result.tier).toBe(2);
        expect(result.priceCents).toBe(2500);
    });

    it('tier 3 for 20 items (lower boundary of tier 3)', () => {
        const result = calculateVolumeTier(20);
        expect(result.tier).toBe(3);
        expect(result.priceCents).toBe(2000);
        expect(result.label).toContain('20€');
    });

    it('tier 3 for 21 items', () => {
        const result = calculateVolumeTier(21);
        expect(result.tier).toBe(3);
        expect(result.priceCents).toBe(2000);
    });

    it('tier 3 for 100 items (large count)', () => {
        const result = calculateVolumeTier(100);
        expect(result.tier).toBe(3);
        expect(result.priceCents).toBe(2000);
    });

    it('accepts a custom config', () => {
        const customConfig = {
            ...DEFAULT_VOLUME_PRICING,
            tier2Threshold: 5,
            tier3Threshold: 15,
            tier1PriceCents: 5000,
            tier2PriceCents: 4000,
            tier3PriceCents: 3000,
        };
        // 3 items → tier 1
        expect(calculateVolumeTier(3, customConfig).tier).toBe(1);
        expect(calculateVolumeTier(3, customConfig).priceCents).toBe(5000);
        // 5 items → tier 2
        expect(calculateVolumeTier(5, customConfig).tier).toBe(2);
        expect(calculateVolumeTier(5, customConfig).priceCents).toBe(4000);
        // 15 items → tier 3
        expect(calculateVolumeTier(15, customConfig).tier).toBe(3);
        expect(calculateVolumeTier(15, customConfig).priceCents).toBe(3000);
    });
});

describe('calculateVolumeTotal', () => {
    it('returns 0 for an empty array', () => {
        expect(calculateVolumeTotal([])).toBe(0);
    });

    it('calculates total for 3 items at tier 1 (3000 each)', () => {
        const items = [
            {priceCents: 999}, // the individual priceCents field is ignored in volume pricing
            {price: 999},
            {price: 999},
        ];
        // 3 items × 3000 = 9000
        expect(calculateVolumeTotal(items)).toBe(9000);
    });

    it('calculates total for 10 items at tier 2 (2500 each)', () => {
        const items = Array.from({length: 10}, () => ({price: 0}));
        expect(calculateVolumeTotal(items)).toBe(10 * 2500);
    });

    it('calculates total for 20 items at tier 3 (2000 each)', () => {
        const items = Array.from({length: 20}, () => ({price: 0}));
        expect(calculateVolumeTotal(items)).toBe(20 * 2000);
    });

    it('retroactive: all items at same price regardless of stored individual prices', () => {
        const items = [
            {price: 9999},
            {price: 1111},
            {price: 5555},
        ];
        // 3 items at tier 1 (3000 each)
        expect(calculateVolumeTotal(items)).toBe(3 * 3000);
    });

    it('works with custom config', () => {
        const customConfig = {
            ...DEFAULT_VOLUME_PRICING,
            tier1PriceCents: 1000,
            tier2PriceCents: 800,
            tier3PriceCents: 500,
        };
        const items = Array.from({length: 5}, () => ({price: 0}));
        expect(calculateVolumeTotal(items, customConfig)).toBe(5 * 1000);
    });

    it('includes quote items in count for volume tier calculation', () => {
        // 2 normal + 1 quote = 3 items → tier 1 (3000 each)
        const items = [
            {price: 1000, isQuote: false},
            {price: 1500, isQuote: false},
            {price: 0, isQuote: true},
        ];
        // Retroactive: all 3 items at 3000 = 9000
        expect(calculateVolumeTotal(items)).toBe(3 * 3000);
    });
});
