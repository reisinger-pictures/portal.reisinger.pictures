import {describe, it, expect} from 'vitest';
import {RES_RANKS, getRequiredTerm, getRequiredMultiplier, isCovered, calculateUpgradePrice, PricingTerms} from '../pricingLogic';

// Beispiel-Terms (handgerechnet, Integer — die Logik parst ALLE Faktoren via parseInt):
//   price_print − price_web = 1500;
//   price_original · mult_commercial(2) · mult_unlimited(3) · mult_international(2) − price_web = 17000.
const TERMS: PricingTerms = {
    price_web: '1000',
    price_print: '2500',
    price_original: '1500',
    mult_commercial: '2',
    mult_unlimited: '3',
    mult_international: '2',
};

describe('RES_RANKS', () => {
    it('maps resolution tiers to ascending ranks', () => {
        expect(RES_RANKS).toEqual({none: 0, web: 1, print: 2, original: 3});
    });
});

describe('getRequiredTerm', () => {
    it('returns 0 while terms are not loaded (null/undefined)', () => {
        expect(getRequiredTerm(null, 'price_web')).toBe(0);
        expect(getRequiredTerm(undefined, 'price_web')).toBe(0);
    });

    it('parses a numeric string value', () => {
        expect(getRequiredTerm({price_web: '1000'}, 'price_web')).toBe(1000);
    });

    it('throws when the factor is missing', () => {
        expect(() => getRequiredTerm({price_web: '1000'}, 'price_print')).toThrow();
    });

    it('throws on empty / non-numeric value', () => {
        expect(() => getRequiredTerm({price_web: ''}, 'price_web')).toThrow();
        expect(() => getRequiredTerm({price_web: 'abc'}, 'price_web')).toThrow();
    });
});

describe('getRequiredMultiplier', () => {
    it('returns 1 while terms are not loaded (null/undefined) — neutral multiplier', () => {
        expect(getRequiredMultiplier(null, 'mult_commercial')).toBe(1);
        expect(getRequiredMultiplier(undefined, 'mult_commercial')).toBe(1);
    });

    it('parses an integer multiplier', () => {
        expect(getRequiredMultiplier({mult_commercial: '2'}, 'mult_commercial')).toBe(2);
    });

    // R-05 · P1 · Bugfix: dezimale Multiplikatoren dürfen NICHT trunciert werden.
    // Vor dem Fix parste getRequiredTerm('1.5') via parseInt → 1 (stiller Preisverlust).
    it('parses a decimal multiplier WITHOUT truncation (R-05 fix)', () => {
        expect(getRequiredMultiplier({mult_unlimited: '1.5'}, 'mult_unlimited')).toBe(1.5);
        expect(getRequiredMultiplier({mult_commercial: '2.0'}, 'mult_commercial')).toBe(2);
        expect(getRequiredMultiplier({mult_international: '1.25'}, 'mult_international')).toBe(1.25);
    });

    it('throws when the multiplier is missing', () => {
        expect(() => getRequiredMultiplier({mult_commercial: '2'}, 'mult_unlimited')).toThrow();
    });

    it('throws on empty / non-numeric value', () => {
        expect(() => getRequiredMultiplier({mult_commercial: ''}, 'mult_commercial')).toThrow();
        expect(() => getRequiredMultiplier({mult_commercial: 'abc'}, 'mult_commercial')).toThrow();
    });
});

describe('isCovered', () => {
    it('is false for commercial usage regardless of resolution', () => {
        expect(isCovered(TERMS, 'original', 'web', 'commercial', '1_year', 'national')).toBe(false);
    });

    it('is false for unlimited duration', () => {
        expect(isCovered(TERMS, 'original', 'web', 'editorial', 'unlimited', 'national')).toBe(false);
    });

    it('is false for international territory', () => {
        expect(isCovered(TERMS, 'original', 'web', 'editorial', '1_year', 'international')).toBe(false);
    });

    it('is true when user rank >= requested rank (editorial/1_year/national)', () => {
        expect(isCovered(TERMS, 'original', 'web', 'editorial', '1_year', 'national')).toBe(true);
        expect(isCovered(TERMS, 'web', 'web', 'editorial', '1_year', 'national')).toBe(true);
    });

    it('is false when user rank < requested rank', () => {
        expect(isCovered(TERMS, 'web', 'print', 'editorial', '1_year', 'national')).toBe(false);
    });

    it('treats undefined / "none" user level as rank 0 (covers nothing)', () => {
        expect(isCovered(TERMS, undefined, 'web', 'editorial', '1_year', 'national')).toBe(false);
        expect(isCovered(TERMS, 'none', 'web', 'editorial', '1_year', 'national')).toBe(false);
    });

    it('defaults territory to national when omitted', () => {
        expect(isCovered(TERMS, 'original', 'web', 'editorial', '1_year')).toBe(true);
    });
});

describe('calculateUpgradePrice', () => {
    it('returns 0 when already covered', () => {
        expect(calculateUpgradePrice(TERMS, 'original', 'web', 'editorial', '1_year', 'national')).toBe(0);
    });

    it('example: web → print (editorial) costs 1500', () => {
        expect(calculateUpgradePrice(TERMS, 'web', 'print', 'editorial', '1_year', 'national')).toBe(1500);
    });

    it('example: web → original (commercial/unlimited/international) costs 17000', () => {
        expect(calculateUpgradePrice(TERMS, 'web', 'original', 'commercial', 'unlimited', 'international')).toBe(17000);
    });

    it('clamps a negative delta to 0 (max(delta, 0))', () => {
        // print user (price 2500), web req, commercial → requested 1000·2 = 2000, delta −500 → 0
        expect(calculateUpgradePrice(TERMS, 'print', 'web', 'commercial', '1_year', 'national')).toBe(0);
    });

    it('treats "none" user level as no credit (userPrice 0)', () => {
        expect(calculateUpgradePrice(TERMS, 'none', 'print', 'editorial', '1_year', 'national')).toBe(2500);
    });

    it('treats undefined user level as no credit', () => {
        expect(calculateUpgradePrice(TERMS, undefined, 'print', 'editorial', '1_year', 'national')).toBe(2500);
    });

    it('treats an unknown user level as no credit', () => {
        expect(calculateUpgradePrice(TERMS, 'foo', 'print', 'editorial', '1_year', 'national')).toBe(2500);
    });

    it('applies the commercial multiplier', () => {
        // print (2500) · 2 − web (1000) = 4000
        expect(calculateUpgradePrice(TERMS, 'web', 'print', 'commercial', '1_year', 'national')).toBe(4000);
    });

    it('applies the unlimited-duration multiplier', () => {
        // print (2500) · 3 − web (1000) = 6500
        expect(calculateUpgradePrice(TERMS, 'web', 'print', 'editorial', 'unlimited', 'national')).toBe(6500);
    });

    it('applies the international-territory multiplier', () => {
        // print (2500) · 2 − web (1000) = 4000
        expect(calculateUpgradePrice(TERMS, 'web', 'print', 'editorial', '1_year', 'international')).toBe(4000);
    });

    // R-05 · P1 · Bugfix-Regression: ein DEZIMALER Multiplikator darf nicht trunciert werden.
    // Vor dem Fix: mult_commercial='1.5' → parseInt → 1 → web(1000)·1 − web(1000) = 0 (Bug).
    // Nach Fix:    web(1000) · 1.5 − web(1000) = 500. Backend liefert mult_* dezimal (numeric|min:1).
    it('R-05: decimal mult_commercial=1.5 is preserved (500 delta, not 0)', () => {
        const decimalTerms: PricingTerms = {
            price_web: '1000',
            price_print: '2500',
            price_original: '1500',
            mult_commercial: '1.5',
            mult_unlimited: '3',
            mult_international: '2',
        };
        expect(calculateUpgradePrice(decimalTerms, 'web', 'web', 'commercial', '1_year', 'national')).toBe(500);
    });
});
