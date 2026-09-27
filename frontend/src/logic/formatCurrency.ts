/**
 * Currency and percentage formatting for the German UI.
 *
 * `Number.prototype.toFixed(2)` is locale-independent and always emits a period
 * as the decimal separator. In an all-German quoting and billing UI that
 * produces "369.00 €" instead of "369,00 €", and it stays wrong no matter which
 * locale the visitor's browser is set to. Everything that renders an amount for
 * a human therefore goes through {@link formatEuro} (amounts that own their
 * symbol), {@link formatEuroWhole} and {@link formatEuroDecimal} (amounts inside
 * a message) or {@link formatPercent} (percentages).
 *
 * There is exactly one formatter per unit. A second name for the same unit is
 * how "10%" and "10,00 %" end up in the same table: a percentage that is written
 * out at the call site stops being compared against the one next to it.
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
 *
 * `Intl.NumberFormat` on a *divided* percentage is also deliberately not used;
 * see {@link formatPercent} for why the digits are split off with BigInt. Only
 * the whole part is formatted here, so this instance carries no decimals of its
 * own.
 */
const PERCENT_WHOLE_FORMATTER = new Intl.NumberFormat('de-DE', {
    maximumFractionDigits: 0,
});

/**
 * Formats a percentage for display, from the unit the API actually stores:
 * hundredths of a percent, i.e. basis points (1000 → "10,00 %").
 *
 * The digits are taken apart with BigInt instead of dividing first, because
 * `basisPoints / 100` is a float division. Near the top of the safe-integer
 * range the double it produces is off by up to 0.0078, which is enough to round
 * the second decimal the wrong way: `Number.MAX_SAFE_INTEGER` basis points
 * renders as "90.071.992.547.409,90 %" through the float and as the correct
 * "90.071.992.547.409,91 %" here, and 7.84 % of all safe integers disagree.
 * Contract prices are read and written with that exactness in mind, so the
 * float detour stays out of the formatter even though it would be one line.
 *
 * Output shape: German decimal separator, exactly two decimals (a discount of
 * 10 % and one of 10.5 % line up in the column), unit behind the same
 * non-breaking space {@link formatEuro} uses. Anything that is not a safe
 * integer — a fraction, a `NaN`, an `Infinity` — yields a placeholder instead of
 * a silently wrong percentage.
 *
 * This is the only percentage formatter. It replaces the contract-side
 * `formatBasisPointsAsPercent`, which rendered the same unit with a period
 * decimal separator and without trailing zeros ("12.5%").
 */
export function formatPercent(basisPoints: number): string {
    if (!Number.isSafeInteger(basisPoints)) return `---${NBSP}%`;
    const sign = basisPoints < 0 ? '-' : '';
    // Safe-integer negation is exact, so this cannot overflow into a float.
    const absolute = BigInt(basisPoints < 0 ? -basisPoints : basisPoints);
    // 100 units per percent: the hundredths are the fraction, everything above
    // is the (groupable) whole part. The whole part stays well below 2^53.
    const whole = absolute / 100n;
    const fraction = absolute % 100n;
    return `${sign}${PERCENT_WHOLE_FORMATTER.format(Number(whole))},${fraction.toString().padStart(2, '0')}${NBSP}%`;
}

/**
 * The bare placeholder the message-embedded formatters ({@link formatEuroWhole},
 * {@link formatEuroDecimal}) fall back to. No symbol and no non-breaking space:
 * the symbol belongs to the message this value is embedded in.
 */
const UNDEFINED_BARE_AMOUNT = '---';

/**
 * `Intl` groups the whole part exactly like {@link formatEuro} does, so the only
 * differences to that formatter are the rounding and the missing symbol.
 */
const EURO_WHOLE_FORMATTER = new Intl.NumberFormat('de-DE', {
    maximumFractionDigits: 0,
});

/**
 * Formats a whole-euro amount for a compact price *label*: rounded to full
 * euros, German thousands separator, and deliberately **without a currency
 * symbol**.
 *
 * The symbol belongs to the surrounding message, not to the value. A price that
 * is interpolated into a Lingui template has to stay a single placeable value —
 * if the value also carried the "€", the symbol could no longer be moved by a
 * translator, and the number would be indistinguishable from prose. Billing
 * amounts own their symbol and go through {@link formatEuro}; this formatter is
 * for the ones that live inside a message such as "20 € pro Bild".
 *
 * Rounding is half away from zero on the exact value of the double, i.e.
 * identical to `toFixed(0)` — both round the mathematical value, not a
 * re-parsed decimal string. Non-finite input yields the bare placeholder, so a
 * broken price stays visibly broken ("--- € pro Bild").
 */
export function formatEuroWhole(amount: number): string {
    if (!Number.isFinite(amount)) return UNDEFINED_BARE_AMOUNT;
    const formatted = EURO_WHOLE_FORMATTER.format(amount);
    // `Intl` keeps the sign of a value that rounds to zero ("-0"); a price label
    // must not claim a minus. Only that degenerate output is patched — the
    // rounding itself is left to `Intl`.
    return formatted === '-0' ? '0' : formatted;
}

/**
 * `Intl` groups and rounds exactly like {@link formatEuro}; only the currency
 * symbol is left off, so the surrounding message owns it.
 */
const EURO_DECIMAL_FORMATTER = new Intl.NumberFormat('de-DE', {
    style: 'decimal',
    // Unlike {@link formatEuroWhole} this one must not round cents away.
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Formats a euro amount for a message-embedded value that has to keep its
 * cents: German thousands and decimal separators, exactly two fraction digits,
 * and deliberately **without a currency symbol**.
 *
 * Sibling of {@link formatEuroWhole}. That formatter rounds to whole euros,
 * which is right for a terse label such as "30 € pro Bild" but wrong for a line
 * that renders cents ("30,00 € pro Bild"): rounding would silently rewrite the
 * amount. This one keeps the cents and only moves the symbol out of the value,
 * so a translator can still reorder number and "€" and the symbol can be styled
 * separately.
 *
 * The unit is euros (major units), matching {@link formatEuro} — a call site
 * holding cents divides by 100 first. The output carries neither a symbol nor a
 * non-breaking space; the message it is interpolated into supplies both.
 * Non-finite input yields the bare placeholder, so a broken amount stays
 * visibly broken.
 */
export function formatEuroDecimal(amount: number): string {
    if (!Number.isFinite(amount)) return UNDEFINED_BARE_AMOUNT;
    return EURO_DECIMAL_FORMATTER.format(amount);
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
