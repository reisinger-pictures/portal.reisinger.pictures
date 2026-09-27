/**
 * Currency formatting for the German UI.
 *
 * `Number.prototype.toFixed(2)` is locale-independent and always emits a period
 * as the decimal separator. In an all-German quoting and billing UI that
 * produces "369.00 €" instead of "369,00 €", and it stays wrong no matter which
 * locale the visitor's browser is set to. Everything that renders an amount for
 * a human therefore goes through {@link formatEuro}.
 *
 * Number *inputs* are a different case and deliberately stay unformatted — see
 * {@link formatEuroInputValue}.
 */

/**
 * The locale is pinned to `de-DE` instead of being inherited from the browser:
 * the UI language is a product decision, not a visitor preference, and a
 * hard-coded locale keeps the output identical for every client.
 */
const EURO_FORMATTER = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    // A billing tool must neither drop nor round away cents.
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * `Intl` places a U+00A0 NO-BREAK SPACE between amount and symbol for de-DE, so
 * "1.234,50 €" can never be split across a line break. The placeholder reuses
 * the same space so both strings share one metric.
 */
const NBSP = '\u00A0';

const UNDEFINED_AMOUNT = `---${NBSP}€`;

/**
 * Formats an amount in euros (major units) for display: German thousands and
 * decimal separators, exactly two decimals, symbol trailing behind a
 * non-breaking space.
 *
 * Non-finite input yields a placeholder instead of "NaN €", so a broken value
 * stays visibly broken rather than being mistaken for a real amount. The guard
 * lives here so every call site inherits it.
 */
export function formatEuro(amount: number): string {
    if (!Number.isFinite(amount)) return UNDEFINED_AMOUNT;
    return EURO_FORMATTER.format(amount);
}

/**
 * Product and discount rows share one `price` column but two different units:
 * cents for `discount_fixed`/`item`, and hundredths of a percent for
 * `discount_percent`. A percentage therefore has to be localised just as
 * carefully as a currency amount — a period here would sit right next to a
 * comma inside the same table cell.
 *
 * `Intl`'s `percent` style is deliberately NOT used: it multiplies by 100, and
 * the stored values are already scaled to hundredths (1000 → "10,00 %").
 */
const PERCENT_FORMATTER = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Formats a percentage (major units, i.e. already divided by 100) for display:
 * German decimal separator, exactly two decimals, unit behind the same
 * non-breaking space {@link formatEuro} uses.
 *
 * Non-finite input yields a placeholder, matching {@link formatEuro}.
 */
export function formatPercent(percent: number): string {
    if (!Number.isFinite(percent)) return `---${NBSP}%`;
    return `${PERCENT_FORMATTER.format(percent)}${NBSP}%`;
}

/**
 * Formats an amount in euros for a controlled `<input type="number">` draft.
 *
 * Deliberately NOT localised: a number input rejects a comma decimal separator
 * (the value sanitiser clears the field), and the surrounding parser reads it
 * back with `Number.parseFloat`, which would stop at the first comma and
 * silently bill "1.234" instead of "1,234". This is a machine value, not
 * presentation — anything a human reads goes through {@link formatEuro}.
 *
 * Non-finite input falls back to `'0.00'`, matching the "an empty draft means
 * zero" contract the price parsing layer already uses.
 */
export function formatEuroInputValue(amount: number): string {
    return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
}
