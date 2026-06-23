import {describe, it, expect} from 'vitest';
import {roundToPsychologicalValue, calculateShootingPrice, ShootingPriceInput, calculateB2CFlexPrice} from '../shootingCalculator';

const defaults = (overrides: Partial<ShootingPriceInput> = {}): ShootingPriceInput => ({
    calc_base_price: '50',
    calc_hourly_rate: '100',
    calc_images_per_hour: '6',
    calc_outdoor_multiplier: '0.5',
    duration: 90,
    images: 15,
    isOutdoor: false,
    flatrate: false,
    discount: '0',
    ...overrides,
});

describe('roundToPsychologicalValue', () => {
    it('clamps sub-12 values to a minimum of 1', () => {
        expect(roundToPsychologicalValue(0)).toBe(1);
        expect(roundToPsychologicalValue(5.5)).toBe(6);
    });

    it('rounds the lower boundary (12) to a …9 value', () => {
        expect(roundToPsychologicalValue(12)).toBe(9);
    });

    it('rounds mid-range values to the next 5er and subtracts 1 on …0', () => {
        expect(roundToPsychologicalValue(13)).toBe(15);
        expect(roundToPsychologicalValue(20)).toBe(19);
        expect(roundToPsychologicalValue(100)).toBe(99);
    });

    it('uses a 50er grid at/above 1000 and subtracts 1 on clean multiples', () => {
        expect(roundToPsychologicalValue(1000)).toBe(999);
        expect(roundToPsychologicalValue(1026)).toBe(1049);
        expect(roundToPsychologicalValue(1075)).toBe(1099);
    });

    it('clamps negative values to a minimum of 1', () => {
        expect(roundToPsychologicalValue(-5)).toBe(1);
    });
});

describe('calculateShootingPrice', () => {
    it('defaults (90 min / 15 imgs, no flatrate, no discount) → {449,449,0}', () => {
        expect(calculateShootingPrice(defaults())).toEqual({packagePrice: 449, finalPrice: 449, discountAbsolute: 0});
    });

    it('halves the images price component when isOutdoor is true', () => {
        // Base 50 + Time 150 + (Images 250 * 0.5 = 125) = 325 -> gerundet auf 325
        expect(calculateShootingPrice(defaults({isOutdoor: true}))).toEqual({packagePrice: 325, finalPrice: 325, discountAbsolute: 0});
    });

    it('flatrate (+20%) → {539,539,0}', () => {
        expect(calculateShootingPrice(defaults({flatrate: true}))).toEqual({packagePrice: 539, finalPrice: 539, discountAbsolute: 0});
    });

    it('33% discount → {449,299,150}', () => {
        expect(calculateShootingPrice(defaults({discount: '33'}))).toEqual({packagePrice: 449, finalPrice: 299, discountAbsolute: 150});
    });

    it('50% discount → {449,225,224} (desired inexact rounding)', () => {
        expect(calculateShootingPrice(defaults({discount: '50'}))).toEqual({packagePrice: 449, finalPrice: 225, discountAbsolute: 224});
    });

    it('honours a custom base price', () => {
        expect(calculateShootingPrice(defaults({calc_base_price: '100'}))).toEqual({packagePrice: 499, finalPrice: 499, discountAbsolute: 0});
    });

    it('zero duration removes the time component → {299,299,0}', () => {
        expect(calculateShootingPrice(defaults({duration: 0}))).toEqual({packagePrice: 299, finalPrice: 299, discountAbsolute: 0});
    });

    it('zero images removes the images component → {199,199,0}', () => {
        expect(calculateShootingPrice(defaults({images: 0}))).toEqual({packagePrice: 199, finalPrice: 199, discountAbsolute: 0});
    });

    it('calc_images_per_hour "0" falls back to default 6 (no Infinity)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '0'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 449, finalPrice: 449, discountAbsolute: 0});
    });

    it('combines flatrate, outdoor and discount correctly', () => {
        // (Base 50 + Time 150 + (Images 250 * 0.5 = 125)) * 1.2 = 325 * 1.2 = 390 -> gerundet auf 389
        // 389 - 50% = 194.5 -> gerundet auf 195
        expect(calculateShootingPrice(defaults({flatrate: true, isOutdoor: true, discount: '50'}))).toEqual({packagePrice: 389, finalPrice: 195, discountAbsolute: 194});
    });

    it('honours a custom hourly rate', () => {
        expect(calculateShootingPrice(defaults({calc_hourly_rate: '200'}))).toEqual({packagePrice: 849, finalPrice: 849, discountAbsolute: 0});
    });

    it('honours a custom images-per-hour', () => {
        expect(calculateShootingPrice(defaults({calc_images_per_hour: '10'}))).toEqual({packagePrice: 349, finalPrice: 349, discountAbsolute: 0});
    });

    it('non-numeric calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: 'abc'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 449, finalPrice: 449, discountAbsolute: 0});
    });

    it('negative calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '-5'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 449, finalPrice: 449, discountAbsolute: 0});
    });
});

describe('calculateB2CFlexPrice', () => {
    it('calculates portrait correctly', () => {
        expect(calculateB2CFlexPrice({ type: 'portrait', setup: 'outdoor', extraImages: 0, isFullyPrivate: false })).toEqual({ packagePrice: 149, finalPrice: 149, discountAbsolute: 0 });
    });
    it('calculates couple indoor correctly', () => {
        expect(calculateB2CFlexPrice({ type: 'couple', setup: 'indoor', extraImages: 5, isFullyPrivate: false })).toEqual({ packagePrice: 274, finalPrice: 274, discountAbsolute: 0 });
    });
    it('calculates nude outdoor private correctly', () => {
        expect(calculateB2CFlexPrice({ type: 'nude', setup: 'outdoor', extraImages: 0, isFullyPrivate: true })).toEqual({ packagePrice: 349, finalPrice: 349, discountAbsolute: 0 });
    });
    it('calculates nude indoor private correctly', () => {
        expect(calculateB2CFlexPrice({ type: 'nude', setup: 'indoor', extraImages: 10, isFullyPrivate: true })).toEqual({ packagePrice: 599, finalPrice: 599, discountAbsolute: 0 });
    });
});
