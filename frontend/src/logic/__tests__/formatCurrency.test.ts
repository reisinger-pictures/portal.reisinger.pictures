import { describe, it, expect } from 'vitest';
import { formatEuro, formatEuroDecimal, formatEuroInputValue, formatEuroWhole, formatPercent } from '../formatCurrency';

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

describe('formatPercent', () => {
    it('formats a stored percentage (hundredths of a percent) with exactly two decimals', () => {
        // The API stores hundredths of a percent, so 1000 is 10 %.
        expect(formatPercent(1000)).toBe(`10,00${NBSP}%`);
        expect(formatPercent(0)).toBe(`0,00${NBSP}%`);
        expect(formatPercent(750)).toBe(`7,50${NBSP}%`);
        expect(formatPercent(1001)).toBe(`10,01${NBSP}%`);
    });

    it('regression: never emits the locale-independent toFixed(2) output', () => {
        // The old product tables rendered `${(price / 100).toFixed(2)} %`, i.e.
        // a percent sat next to a German amount in the same column.
        expect(formatPercent(750)).not.toBe('7.50 %');
        expect(formatPercent(750)).not.toContain('7.50');
    });

    it('regression: never emits the old contract-side formatBasisPointsAsPercent output', () => {
        // That helper rendered the same unit with a period decimal separator and
        // without trailing zeros, so the signing view showed "12.5%" next to
        // "10,00 %" in the product tables.
        expect(formatPercent(1250)).not.toBe('12.5%');
        expect(formatPercent(1000)).not.toBe('10%');
        expect(formatPercent(1250)).toBe(`12,50${NBSP}%`);
    });

    it('uses a period as thousands separator and a comma as decimal separator', () => {
        expect(formatPercent(123450)).toBe(`1.234,50${NBSP}%`);
        expect(formatPercent(10_000)).toBe(`100,00${NBSP}%`);
    });

    it('never appends a currency symbol to a percentage', () => {
        // The product `price` column mixes euro amounts with percentages; a
        // percentage must not be dressed as money.
        expect(formatPercent(1000)).not.toContain('€');
    });

    it('keeps a minus sign in front of the grouped digits', () => {
        expect(formatPercent(-1500)).toBe(`-15,00${NBSP}%`);
        expect(formatPercent(-123450)).toBe(`-1.234,50${NBSP}%`);
    });

    it('splits the digits without float division, so the extreme is exact', () => {
        // `Number.MAX_SAFE_INTEGER / 100` renders as "…409,90 %" through a float
        // formatter; the BigInt split keeps the hundredth.
        expect(formatPercent(Number.MAX_SAFE_INTEGER))
            .toBe(`90.071.992.547.409,91${NBSP}%`);
    });

    it('returns a placeholder for anything that is not a stored integer', () => {
        // A fraction has no hundredths to show, and rounding it silently would
        // invent precision the payload does not carry.
        expect(formatPercent(7.5)).toBe(`---${NBSP}%`);
        expect(formatPercent(Number.NaN)).toBe(`---${NBSP}%`);
        expect(formatPercent(Number.POSITIVE_INFINITY)).toBe(`---${NBSP}%`);
        expect(formatPercent(Number.NEGATIVE_INFINITY)).toBe(`---${NBSP}%`);
        expect(formatPercent(Number.MAX_SAFE_INTEGER + 1)).toBe(`---${NBSP}%`);
    });
});

describe('formatEuroWhole', () => {
    it('rounds to full euros and groups thousands', () => {
        expect(formatEuroWhole(30)).toBe('30');
        expect(formatEuroWhole(1200)).toBe('1.200');
        expect(formatEuroWhole(29.99)).toBe('30');
        expect(formatEuroWhole(0)).toBe('0');
    });

    it('rounds exactly like the toFixed(0) it replaces', () => {
        // The volume price label used to interpolate `(priceCents / 100).toFixed(0)`.
        // Only the grouping differs, so compare the digits.
        for (const cents of [0, 1, 49, 50, 51, 250, 2999, 3000, 123456, 99999999]) {
            expect(formatEuroWhole(cents / 100).replace(/\./g, '')).toBe((cents / 100).toFixed(0));
        }
    });

    it('never renders a negative zero', () => {
        // `Intl` renders -0.4 as "-0"; a price label must not claim a minus.
        expect(formatEuroWhole(-0.4)).toBe('0');
        expect(formatEuroWhole(-0)).toBe('0');
    });

    it('carries no currency symbol and no non-breaking space', () => {
        // The symbol belongs to the message the value is interpolated into, so
        // translators can still move it.
        expect(formatEuroWhole(1200)).not.toContain('€');
        expect(formatEuroWhole(1200)).not.toContain(NBSP);
    });

    it('returns a placeholder instead of NaN for non-finite input', () => {
        expect(formatEuroWhole(Number.NaN)).toBe('---');
        expect(formatEuroWhole(Number.POSITIVE_INFINITY)).toBe('---');
        expect(formatEuroWhole(Number.NEGATIVE_INFINITY)).toBe('---');
    });
});

describe('formatEuroDecimal', () => {
    it('keeps the cents, groups thousands and uses a comma as decimal separator', () => {
        expect(formatEuroDecimal(30)).toBe('30,00');
        expect(formatEuroDecimal(1.05)).toBe('1,05');
        expect(formatEuroDecimal(1234.5)).toBe('1.234,50');
        expect(formatEuroDecimal(0)).toBe('0,00');
    });

    it('keeps the cents instead of rounding them away like formatEuroWhole', () => {
        // The one difference that matters: this formatter is for lines that
        // *render* cents, so 29.99 must not collapse to "30".
        expect(formatEuroDecimal(29.99)).toBe('29,99');
        expect(formatEuroDecimal(2999 / 100)).not.toBe(formatEuroWhole(29.99));
    });

    it('carries no currency symbol and no non-breaking space', () => {
        // The symbol belongs to the message the value is interpolated into.
        expect(formatEuroDecimal(1234.5)).not.toContain('€');
        expect(formatEuroDecimal(1234.5)).not.toContain(NBSP);
    });

    it('reproduces formatEuro exactly when the message adds the symbol', () => {
        // This is the refactor's contract: the value plus the symbol (behind the
        // same non-breaking space) renders the identical string a call site used
        // to get from formatEuro alone.
        for (const amount of [0, 1.05, 30, 1234.5, 9999.99, -5, 29.99]) {
            expect(`${formatEuroDecimal(amount)}${NBSP}€`).toBe(formatEuro(amount));
        }
    });

    it('returns a placeholder instead of NaN for non-finite input', () => {
        expect(formatEuroDecimal(Number.NaN)).toBe('---');
        expect(formatEuroDecimal(Number.POSITIVE_INFINITY)).toBe('---');
        expect(formatEuroDecimal(Number.NEGATIVE_INFINITY)).toBe('---');
    });
});
