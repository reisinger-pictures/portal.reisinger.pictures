// Pure Shooting-Calculator-Logik (aus ShootingCalculatorModal.tsx extrahiert, verhaltensgleich).
// Psychologische Rundung ist GEWÜNSCHTES Verhalten — siehe features/ecommerce/07-psychological-pricing.md.

/**
 * Cents per euro — the single scale between the euro-facing UI and the
 * cent-valued money the API serves and stores (owner decision 2026-09-28:
 * every monetary amount is cents, in storage and on the wire, whole-euro
 * amounts included).
 *
 * Named once and used for every conversion instead of a bare `100` so that a
 * second, different scale can never be introduced next to it.
 */
export const CENTS_PER_EURO = 100;

/** Fallback when `calc_base_price` is absent. Cents, like every money value. */
export const DEFAULT_BASE_PRICE = 5000;
/** Fallback when `calc_hourly_rate` is absent. Cents, like every money value. */
export const DEFAULT_HOURLY_RATE = 8000;
export const DEFAULT_IMAGES_PER_HOUR = 6;
export const DEFAULT_OUTDOOR_IMAGES_PER_HOUR = '8';
export const DEFAULT_FLATRATE_MULTIPLIER = '1.2';
export const DEFAULT_SRP_BASE_PRICE = 149;
export const DEFAULT_SRP_SETUP_FEE = 50;
export const DEFAULT_SRP_PRIVACY_FEE = 200;
export const DEFAULT_SRP_EXTRA_IMAGE_FEE = 15;

export function safeParseInt(value: string | undefined | null, fallback: number): number {
    const parsed = parseInt(value ?? '', 10);
    return Number.isFinite(parsed) ? parsed : fallback;
}

/**
 * Tolerant float read for a settings value.
 *
 * Accepts a `number` because the licence-terms API types its money fields as
 * JSON integers (owner decision 2026-09-27) — a `string` is still accepted
 * because SWR caches, the E2E session helper and hand-written callers hand
 * over the raw `settings.value` text, and because a non-numeric string has to
 * fall back rather than produce `NaN`.
 */
export function safeParseFloat(value: string | number | undefined | null, fallback: number): number {
    const parsed = typeof value === 'number' ? value : parseFloat(value ?? '');
    return Number.isFinite(parsed) ? parsed : fallback;
}

export type ShootingDiscount = '0' | '33' | '50';

export interface ShootingPriceInput {
    /** Cents, like every money value the API serves. */
    calc_base_price?: string | number;
    /** Cents, like every money value the API serves. */
    calc_hourly_rate?: string | number;
    /** A count, not money — still served as the `settings.value` text. */
    calc_images_per_hour?: string;
    calc_outdoor_images_per_hour?: string;
    /** A dimensionless factor, not money — still served as the text. */
    calc_flatrate_multiplier?: string;
    duration: number; // Minuten
    images: number;
    isOutdoor: boolean;
    flatrate: boolean;
    discount: ShootingDiscount;
    isReorder: boolean;
}

/**
 * Price triple of a calculator.
 *
 * The unit is the calculator's own, and the two differ on purpose:
 * `calculateCustomStudioPrice()` computes in **cents** (owner decision
 * 2026-09-28) and returns cents; `calculateB2CFlexPrice()` reads euro amounts
 * and returns euros. Every caller states which one it holds — `ShootingCalculatorModal`
 * divides the studio result by {@link CENTS_PER_EURO} for display and for the
 * invoice line, and passes the flex result through unchanged.
 */
export interface ShootingPriceResult {
    packagePrice: number;
    finalPrice: number;
    discountAbsolute: number; // packagePrice − finalPrice
}

/**
 * Psychological rounding, in **cents** (features/ecommerce/07-psychological-pricing.md).
 *
 * Owner decision 2026-09-28: the calculator computes in cents, and the rounding
 * must go on producing the price it produced before. Every threshold and grid
 * below is the pre-conversion euro value scaled by {@link CENTS_PER_EURO}.
 * Two of those are not scales and are where this conversion can silently
 * change a price:
 *
 *   - `rounded % 10 === 0`  →  `rounded % (10 * CENTS_PER_EURO) === 0`
 *   - `rounded % 50 === 0`  →  `rounded % (50 * CENTS_PER_EURO) === 0`
 *
 * Those ask "is the rounded price a whole multiple of 10 € / 50 €?" — a
 * *divisibility* question, not a magnitude. On a cent amount the divisor has to
 * scale with the unit; leaving it at `10` would make every price a multiple of
 * 10 cents and the …9 suffix would never apply at all.
 *
 * The `rounded -= 1` that answers that test is the third such step: it produces
 * the …9 / …49 *euro* ending, so it subtracts **one euro**, i.e.
 * `CENTS_PER_EURO` cents. Leaving it at `1` cent does not produce 9,99 € where
 * the euro version produced 9,00 € — it produces a different price for every
 * amount that lands on a multiple of 10 € or 50 € (12,00 € → 9,00 € today, but
 * 9,99 € with an unscaled decrement).
 * `shootingCalculator.test.ts` proves the equivalence against a verbatim copy
 * of the euro implementation instead of against hand-typed numbers.
 *
 * Below 12 € the function returns the amount rounded to the cent with a 1 €
 * floor, where the euro version returned a whole euro. That is the one place
 * where the cent form is deliberately not step-for-step equivalent: a
 * whole-euro snap is not expressible as a cents rule, and keeping the cent is
 * what the conversion is for. The band is pinned explicitly in the test rather
 * than swept into the equivalence claim.
 */
export function roundToPsychologicalValue(value: number): number {
    if (value < 12 * CENTS_PER_EURO) {
        return Math.max(CENTS_PER_EURO, Math.round(value));
    }
    let rounded;
    if (value >= 1000 * CENTS_PER_EURO) {
        rounded = Math.round(value / (50 * CENTS_PER_EURO)) * (50 * CENTS_PER_EURO);
    } else {
        rounded = Math.round(value / (5 * CENTS_PER_EURO)) * (5 * CENTS_PER_EURO);
    }
    if (rounded !== 0
        && (rounded % (10 * CENTS_PER_EURO) === 0
            || (value >= 1000 * CENTS_PER_EURO && rounded % (50 * CENTS_PER_EURO) === 0))) {
        rounded -= CENTS_PER_EURO;
    }
    return rounded;
}

export function calculateShootingPrice(input: ShootingPriceInput): ShootingPriceResult { return calculateCustomStudioPrice(input); }

/**
 * Computes the RP/studio package price entirely in **cents** (owner decision
 * 2026-09-28) and returns cents.
 *
 * Only the two money *inputs* change unit, and everything downstream inherits
 * that unit through them — which is exactly why no line below carries a `× 100`
 * of its own:
 *
 *   - `basePrice` and `hourlyRate` are read straight from the cent-valued API.
 *   - `timePrice = hours × rate` is cents, because the rate is.
 *   - `imagesPrice = (rate / imagesPerHour) × images` is cents too, and needs
 *     **no** extra scale: the only dimensional member is `hourlyRate`, so the
 *     `× 100` arrives through the numerator. Multiplying here as well would be
 *     the double-scaling bug this function is the risk of.
 *   - `multiplier` is dimensionless and `imagesPerHour` is a count, so both are
 *     untouched by the unit change.
 */
export function calculateCustomStudioPrice(input: ShootingPriceInput): ShootingPriceResult {
    const basePrice = input.isReorder ? 0 : safeParseFloat(input.calc_base_price, DEFAULT_BASE_PRICE);
    const hourlyRate = safeParseFloat(input.calc_hourly_rate, DEFAULT_HOURLY_RATE);
    
    const parsedImagesPerHour = parseInt(input.calc_images_per_hour || String(DEFAULT_IMAGES_PER_HOUR), 10);
    let imagesPerHourPackage =
        Number.isFinite(parsedImagesPerHour) && parsedImagesPerHour >= 1
            ? parsedImagesPerHour
            : DEFAULT_IMAGES_PER_HOUR;

    if (input.isOutdoor) {
        const parsedOutdoorImagesPerHour = parseInt(input.calc_outdoor_images_per_hour || String(DEFAULT_OUTDOOR_IMAGES_PER_HOUR), 10);
        imagesPerHourPackage =
            Number.isFinite(parsedOutdoorImagesPerHour) && parsedOutdoorImagesPerHour >= 1
                ? parsedOutdoorImagesPerHour
                : parseFloat(DEFAULT_OUTDOOR_IMAGES_PER_HOUR);
    }

    const durationHours = input.duration / 60;
    const timePrice = durationHours * hourlyRate;
    const imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images;

    const multiplier = input.flatrate ? safeParseFloat(input.calc_flatrate_multiplier, parseFloat(DEFAULT_FLATRATE_MULTIPLIER)) : 1;
    const rawTotal = (basePrice + timePrice + imagesPrice) * multiplier;
    const packagePrice = roundToPsychologicalValue(rawTotal);

    let currentDiscountPercent = 0;
    if (input.discount === '33') currentDiscountPercent = 100 / 3;
    else if (input.discount === '50') currentDiscountPercent = 50;

    const rawFinalPrice = packagePrice - (packagePrice * (currentDiscountPercent / 100));
    const finalPrice = input.discount !== '0' ? roundToPsychologicalValue(rawFinalPrice) : packagePrice;
    const discountAbsolute = packagePrice - finalPrice;

    return {packagePrice, finalPrice, discountAbsolute};
}


export interface B2CFlexInput {
    type: 'portrait' | 'couple' | 'nude';
    setup: 'outdoor' | 'outdoor_flash' | 'indoor';
    extraImages: number;
    isFullyPrivate: boolean;
    // NEU: Dynamische Parameter von der API. `string` as well as `number`
    // because the licence-terms API types its money fields as JSON integers
    // while hand-written callers and cached payloads still hand over text.
    // The amounts are **euros** here: the caller divides the API's cents by
    // CENTS_PER_EURO before passing them in, and the result is euros too.
    srp_base_price?: string | number;
    srp_setup_fee?: string | number;
    srp_privacy_fee?: string | number;
    srp_extra_image_fee?: string | number;
}

export function calculateB2CFlexPrice(input: B2CFlexInput): ShootingPriceResult {
    const basePrice = safeParseFloat(input.srp_base_price, DEFAULT_SRP_BASE_PRICE);
    const setupCost = safeParseFloat(input.srp_setup_fee, DEFAULT_SRP_SETUP_FEE);
    const extraImageCost = safeParseFloat(input.srp_extra_image_fee, DEFAULT_SRP_EXTRA_IMAGE_FEE);
    const privacyBase = safeParseFloat(input.srp_privacy_fee, DEFAULT_SRP_PRIVACY_FEE);

    let setupFee = 0;
    if (input.setup === 'outdoor_flash' || input.setup === 'indoor') {
        setupFee = setupCost;
    }

    const extraImagesFee = input.extraImages * extraImageCost;
    let privacyFee = 0;

    if (input.type === 'nude' && input.isFullyPrivate) {
        privacyFee = privacyBase;
    }

    const total = basePrice + setupFee + extraImagesFee + privacyFee;

    return {
        packagePrice: total,
        finalPrice: total,
        discountAbsolute: 0
    };
}
