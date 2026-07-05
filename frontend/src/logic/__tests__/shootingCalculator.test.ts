import {describe, it, expect} from 'vitest';
import {roundToPsychologicalValue, calculateShootingPrice, ShootingPriceInput, calculateB2CFlexPrice} from '../shootingCalculator';

const defaults = (overrides: Partial<ShootingPriceInput> = {}): ShootingPriceInput => ({
    calc_base_price: '50',
    calc_hourly_rate: '80',
    calc_images_per_hour: '6',
    calc_outdoor_multiplier: '0.5',
    duration: 90,
    images: 15,
    isOutdoor: false,
    flatrate: false,
    discount: '0',
    isReorder: false,
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
        expect(calculateShootingPrice(defaults())).toEqual({packagePrice: 369, finalPrice: 369, discountAbsolute: 0});
    });

    it('halves the images price component when isOutdoor is true', () => {
        // Base 50 + Time 120 + (Images 200 * 0.5 = 100) = 270 -> gerundet auf 269
        expect(calculateShootingPrice(defaults({isOutdoor: true}))).toEqual({packagePrice: 269, finalPrice: 269, discountAbsolute: 0});
    });

    it('flatrate (+20%) → {445,445,0}', () => {
        expect(calculateShootingPrice(defaults({flatrate: true}))).toEqual({packagePrice: 445, finalPrice: 445, discountAbsolute: 0});
    });

    it('33% discount → {369,245,124}', () => {
        expect(calculateShootingPrice(defaults({discount: '33'}))).toEqual({packagePrice: 369, finalPrice: 245, discountAbsolute: 124});
    });

    it('50% discount → {369,185,184} (desired inexact rounding)', () => {
        expect(calculateShootingPrice(defaults({discount: '50'}))).toEqual({packagePrice: 369, finalPrice: 185, discountAbsolute: 184});
    });

    it('honours a custom outdoor multiplier (0.3 = 30%)', () => {
        // Base 50 + Time 120 + (Images 200 * 0.3 = 60) = 230 -> gerundet auf 229
        expect(calculateShootingPrice(defaults({isOutdoor: true, calc_outdoor_multiplier: '0.3'}))).toEqual({packagePrice: 229, finalPrice: 229, discountAbsolute: 0});
    });

    it('outdoor multiplier 1.0 means no reduction (100%)', () => {
        // Base 50 + Time 120 + (Images 200 * 1.0 = 200) = 370 -> gerundet auf 369
        expect(calculateShootingPrice(defaults({isOutdoor: true, calc_outdoor_multiplier: '1.0'}))).toEqual({packagePrice: 369, finalPrice: 369, discountAbsolute: 0});
    });

    it('honours a custom base price', () => {
        expect(calculateShootingPrice(defaults({calc_base_price: '100'}))).toEqual({packagePrice: 419, finalPrice: 419, discountAbsolute: 0});
    });

    it('zero duration removes the time component → {299,299,0}', () => {
        expect(calculateShootingPrice(defaults({duration: 0}))).toEqual({packagePrice: 249, finalPrice: 249, discountAbsolute: 0});
    });

    it('zero images removes the images component → {199,199,0}', () => {
        expect(calculateShootingPrice(defaults({images: 0}))).toEqual({packagePrice: 169, finalPrice: 169, discountAbsolute: 0});
    });

    it('calc_images_per_hour "0" falls back to default 6 (no Infinity)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '0'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 369, finalPrice: 369, discountAbsolute: 0});
    });

    it('combines flatrate, outdoor and discount correctly', () => {
        // (Base 50 + Time 120 + (Images 200 * 0.5 = 100)) * 1.2 = 270 * 1.2 = 324 -> gerundet auf 325
        // 325 - 50% = 162.5 -> gerundet auf 165
        expect(calculateShootingPrice(defaults({flatrate: true, isOutdoor: true, discount: '50'}))).toEqual({packagePrice: 325, finalPrice: 165, discountAbsolute: 160});
    });

    it('honours a custom hourly rate', () => {
        expect(calculateShootingPrice(defaults({calc_hourly_rate: '200'}))).toEqual({packagePrice: 849, finalPrice: 849, discountAbsolute: 0});
    });

    it('honours a custom images-per-hour', () => {
        expect(calculateShootingPrice(defaults({calc_images_per_hour: '10'}))).toEqual({packagePrice: 289, finalPrice: 289, discountAbsolute: 0});
    });

    it('non-numeric calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: 'abc'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 369, finalPrice: 369, discountAbsolute: 0});
    });

    it('negative calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '-5'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 369, finalPrice: 369, discountAbsolute: 0});
    });

    it('reorder skips the base price (calc_base_price = 0)', () => {
        // basePrice=0, time=120, images=200 → 320 → psych 319
        expect(calculateShootingPrice(defaults({isReorder: true}))).toEqual({packagePrice: 319, finalPrice: 319, discountAbsolute: 0});
    });

    it('reorder + outdoor applies outdoor multiplier on images', () => {
        // basePrice=0, time=120, images=200*0.5=100 → 220 → psych 219
        expect(calculateShootingPrice(defaults({isReorder: true, isOutdoor: true}))).toEqual({packagePrice: 219, finalPrice: 219, discountAbsolute: 0});
    });

    it('reorder + flatrate applies +20% on (time + images)', () => {
        // (0 + 120 + 200) * 1.2 = 384 → psych 385
        expect(calculateShootingPrice(defaults({isReorder: true, flatrate: true}))).toEqual({packagePrice: 385, finalPrice: 385, discountAbsolute: 0});
    });

    it('reorder + discount skips base price then applies discount', () => {
        // basePrice=0, time=120, images=200 → 320 → psych 319
        // 319 - 33% = 213.73 → psych 215
        expect(calculateShootingPrice(defaults({isReorder: true, discount: '33'}))).toEqual({packagePrice: 319, finalPrice: 215, discountAbsolute: 104});
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
