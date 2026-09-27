import { describe, it, expect } from 'vitest';
import { formatEuro, formatEuroInputValue } from '../formatCurrency';

/**
 * `Intl` separates the symbol from the amount with U+00A0, never with a plain
 * space — spelled out so the expectation cannot be satisfied by a normal space.
 */
const NBSP = '\u00A0';

describe('formatEuro', () => {
    it('formats a whole amount with exactly two decimals', () => {
        expect(formatEuro(30)).toBe(`30,00${NBSP}€`);
        expect(formatEuro(369)).toBe(`369,00${NBSP}€`);
    });

    it('formats an amount with cents and keeps both decimal places', () => {
        expect(formatEuro(1.05)).toBe(`1,05${NBSP}€`);
        expect(formatEuro(2.5)).toBe(`2,50${NBSP}€`);
    });

    it('uses a period as thousands separator and a comma as decimal separator', () => {
        expect(formatEuro(1234.5)).toBe(`1.234,50${NBSP}€`);
        expect(formatEuro(1234567.89)).toBe(`1.234.567,89${NBSP}€`);
    });

    it('regression: never emits the locale-independent toFixed(2) output', () => {
        // Number.prototype.toFixed always uses a period, in every locale.
        expect(formatEuro(1234.5)).not.toBe('1234.50 €');
        expect(formatEuro(1234.5)).not.toContain('1234.50');
    });

    it('formats zero', () => {
        expect(formatEuro(0)).toBe(`0,00${NBSP}€`);
    });

    it('formats negative amounts with a leading minus sign', () => {
        expect(formatEuro(-5)).toBe(`-5,00${NBSP}€`);
    });

    it('returns a placeholder instead of NaN for non-finite input', () => {
        expect(formatEuro(Number.NaN)).toBe(`---${NBSP}€`);
        expect(formatEuro(Number.POSITIVE_INFINITY)).toBe(`---${NBSP}€`);
        expect(formatEuro(Number.NEGATIVE_INFINITY)).toBe(`---${NBSP}€`);
    });

    it('never renders NaN, whatever the input', () => {
        for (const value of [Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY]) {
            expect(formatEuro(value)).not.toContain('NaN');
        }
    });

    it('rounds to the nearest cent without dropping one', () => {
        expect(formatEuro(9999.994)).toBe(`9.999,99${NBSP}€`);
        expect(formatEuro(0.005)).toBe(`0,01${NBSP}€`);
    });
});

describe('formatEuroInputValue', () => {
    it('stays machine-readable with a period decimal separator', () => {
        // `<input type="number">` and Number.parseFloat both require this.
        expect(formatEuroInputValue(30)).toBe('30.00');
        expect(formatEuroInputValue(1.05)).toBe('1.05');
        expect(formatEuroInputValue(0)).toBe('0.00');
        expect(formatEuroInputValue(1234.5)).toBe('1234.50');
    });

    it('never emits a comma, which a number input would reject', () => {
        expect(formatEuroInputValue(1234.5)).not.toContain(',');
        expect(Number.parseFloat(formatEuroInputValue(1234.5))).toBe(1234.5);
    });

    it('falls back to 0.00 for non-finite input', () => {
        expect(formatEuroInputValue(Number.NaN)).toBe('0.00');
        expect(formatEuroInputValue(Number.POSITIVE_INFINITY)).toBe('0.00');
        expect(formatEuroInputValue(Number.NEGATIVE_INFINITY)).toBe('0.00');
    });
});
