import {describe, it, expect} from 'vitest';
import {
    roundToPsychologicalValue, calculateShootingPrice, ShootingPriceInput, calculateB2CFlexPrice, CENTS_PER_EURO
} from '../shootingCalculator';

const defaults = (overrides: Partial<ShootingPriceInput> = {}): ShootingPriceInput => ({
    calc_base_price: 5000,
    calc_hourly_rate: 8000,
    calc_images_per_hour: '6',
    calc_outdoor_images_per_hour: '8',
    duration: 90,
    images: 15,
    isOutdoor: false,
    flatrate: false,
    discount: '0',
    isReorder: false,
    ...overrides,
});

/**
 * The pre-conversion implementation, verbatim, in **euros** — the reference
 * the cent implementation is measured against (owner decision 2026-09-28:
 * the calculator computes in cents, the rounding must not change a price).
 *
 * Kept as an independent copy on purpose. Asserting the cent function against
 * hand-typed expected numbers would only re-state the new code; asserting it
 * against *this* is what actually proves the transformation, and it is also
 * what caught the one step that does not scale (see the note on
 * `rounded -= 1` in the implementation).
 */
function roundToPsychologicalValueInEuros(value: number): number {
    if (value < 12) {
        return Math.max(1, Math.round(value));
    }
    let rounded;
    if (value >= 1000) {
        rounded = Math.round(value / 50) * 50;
    } else {
        rounded = Math.round(value / 5) * 5;
    }
    if (rounded !== 0 && (rounded % 10 === 0 || (value >= 1000 && rounded % 50 === 0))) {
        rounded -= 1;
    }
    return rounded;
}

/**
 * `calculateCustomStudioPrice` as it stood before the conversion: the same
 * pipeline in euros. Doubles as the second half of the equivalence proof, because
 * the pipeline is where an *unnecessary* `× 100` hides — `imagesPrice` is a
 * ratio that already inherits the cent unit from `hourlyRate` and must not be
 * scaled a second time.
 */
function calculateShootingPriceInEuros(input: {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;
    calc_outdoor_images_per_hour?: string;
    calc_flatrate_multiplier?: string;
    duration: number;
    images: number;
    isOutdoor: boolean;
    flatrate: boolean;
    discount: ShootingPriceInput['discount'];
    isReorder: boolean;
}) {
    const basePrice = input.isReorder ? 0 : parseFloat(input.calc_base_price ?? '') || 50;
    const hourlyRate = parseFloat(input.calc_hourly_rate ?? '') || 80;

    let imagesPerHourPackage = Number(input.calc_images_per_hour || '6');
    if (!Number.isFinite(imagesPerHourPackage) || imagesPerHourPackage < 1) imagesPerHourPackage = 6;
    if (input.isOutdoor) {
        imagesPerHourPackage = Number(input.calc_outdoor_images_per_hour || '8');
        if (!Number.isFinite(imagesPerHourPackage) || imagesPerHourPackage < 1) imagesPerHourPackage = 8;
    }

    const durationHours = input.duration / 60;
    const timePrice = durationHours * hourlyRate;
    const imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images;
    const multiplier = input.flatrate ? (parseFloat(input.calc_flatrate_multiplier ?? '') || 1.2) : 1;
    const rawTotal = (basePrice + timePrice + imagesPrice) * multiplier;
    const packagePrice = roundToPsychologicalValueInEuros(rawTotal);

    const currentDiscountPercent = input.discount === '33' ? 100 / 3 : input.discount === '50' ? 50 : 0;
    const rawFinalPrice = packagePrice - (packagePrice * (currentDiscountPercent / 100));
    const finalPrice = input.discount !== '0' ? roundToPsychologicalValueInEuros(rawFinalPrice) : packagePrice;

    return {packagePrice, finalPrice, discountAbsolute: packagePrice - finalPrice};
}

/** The same `defaults()` case, expressed in the pre-conversion euro unit. */
const euroDefaults = (overrides: Partial<Parameters<typeof calculateShootingPriceInEuros>[0]> = {}) =>
    calculateShootingPriceInEuros({
        calc_base_price: '50',
        calc_hourly_rate: '80',
        calc_images_per_hour: '6',
        calc_outdoor_images_per_hour: '8',
        duration: 90,
        images: 15,
        isOutdoor: false,
        flatrate: false,
        discount: '0',
        isReorder: false,
        ...overrides,
    });

describe('roundToPsychologicalValue', () => {
    it('clamps sub-12-euro values to a 1 € floor', () => {
        expect(roundToPsychologicalValue(0)).toBe(100);
        expect(roundToPsychologicalValue(-500)).toBe(100);
    });

    it('rounds the lower boundary (12 €) to a …9 value', () => {
        expect(roundToPsychologicalValue(1200)).toBe(900);
    });

    it('rounds mid-range values to the next 5 € and subtracts 1 € on …0', () => {
        expect(roundToPsychologicalValue(1300)).toBe(1500);
        expect(roundToPsychologicalValue(2000)).toBe(1900);
        expect(roundToPsychologicalValue(10000)).toBe(9900);
    });

    it('uses a 50 € grid at/above 1000 € and subtracts 1 € on clean multiples', () => {
        expect(roundToPsychologicalValue(100000)).toBe(99900);
        expect(roundToPsychologicalValue(102600)).toBe(104900);
        expect(roundToPsychologicalValue(107500)).toBe(109900);
    });

    /**
     * The one documented deviation, pinned rather than hidden: below 12 € the
     * euro version snapped the price to a whole euro, the cent version keeps
     * the cent. It is the unavoidable consequence of the owner's specified
     * `Math.max(1, …)` → `Math.max(100, …)` floor — a whole-euro snap is not
     * expressible as a cents rule.
     */
    it('keeps the cent below 12 € where the euro version snapped to a whole euro', () => {
        expect(roundToPsychologicalValue(550)).toBe(550);        // 5,50 €
        expect(roundToPsychologicalValue(1125)).toBe(1125);      // 11,25 €
        expect(roundToPsychologicalValueInEuros(5.5)).toBe(6);   // what it used to return
        expect(roundToPsychologicalValueInEuros(11.25)).toBe(11);
    });
});

describe('roundToPsychologicalValue is equivalent to the euro rounding it replaced', () => {
    /**
     * The assertion that would have caught an unscaled step.
     *
     * Collected into an array instead of asserted per iteration: a per-sample
     * `expect()` over millions of amounts is dominated by the assertion
     * bookkeeping and times out on a loaded machine, which is a flaky test
     * rather than a strong one. The collected form keeps every amount covered
     * and prints the first offenders when it does fail.
     */
    it('produces exactly 100× the euro result for every amount at or above 12 €', () => {
        const mismatches: string[] = [];

        // 1-cent steps across the whole 5 €-grid regime, then a 97-cent stride
        // through the 50 €-grid regime up to 30 000 €. With either non-scaling
        // step left unscaled — `% 10` instead of `% 1000`, or `rounded -= 1`
        // instead of `rounded -= 100` — nearly every amount on the grid would
        // diverge and the …9 suffix would be gone.
        const amounts: number[] = [];
        for (let cents = 12 * CENTS_PER_EURO; cents <= 2000 * CENTS_PER_EURO; cents++) {
            amounts.push(cents);
        }
        for (let cents = 2000 * CENTS_PER_EURO; cents <= 30000 * CENTS_PER_EURO; cents += 97) {
            amounts.push(cents);
        }

        for (const cents of amounts) {
            const inCents = roundToPsychologicalValue(cents);
            const inEuros = roundToPsychologicalValueInEuros(cents / CENTS_PER_EURO);
            if (inCents !== inEuros * CENTS_PER_EURO) {
                mismatches.push(`${cents} cents: euro impl ${inEuros} €, cent impl ${inCents / CENTS_PER_EURO} €`);
                if (mismatches.length >= 5) break;
            }
        }

        // Coverage guard, exact rather than a lower bound: a silently shortened
        // sweep would make this test pass for the wrong reason.
        const denseSteps = 2000 * CENTS_PER_EURO - 12 * CENTS_PER_EURO + 1;
        const coarseSteps = Math.floor((30000 * CENTS_PER_EURO - 2000 * CENTS_PER_EURO) / 97) + 1;
        expect(amounts).toHaveLength(denseSteps + coarseSteps);
        expect(mismatches).toEqual([]);
    }, 60_000);

    it('keeps the grid thresholds where they were, in euros', () => {
        // 12 € is the small/large boundary, 1000 € the 5 €/50 € grid boundary.
        // Both stay exactly where they were; only the unit moved.
        const thresholdCases = [
            {euros: 12, cents: 1200},
            {euros: 12.04, cents: 1204},
            {euros: 12.5, cents: 1250},
            {euros: 999.99, cents: 99999},
            {euros: 1000, cents: 100000},
            {euros: 1026, cents: 102600},
        ];
        for (const {euros, cents} of thresholdCases) {
            const inEuros = roundToPsychologicalValueInEuros(euros);
            expect(roundToPsychologicalValue(cents), `at ${cents} cents`).toBe(inEuros * CENTS_PER_EURO);
        }        // The 12 € boundary still switches rule — below it the euro version
        // snapped upwards to a whole euro, above it the 5 € grid with its …9
        // ending takes over. Both sides of the switch are visible here, and the
        // "below" side is the one documented deviation (see above).
        expect(roundToPsychologicalValueInEuros(12)).toBe(9);
        expect(roundToPsychologicalValue(1200)).toBe(900);
        expect(roundToPsychologicalValueInEuros(11.99)).toBe(12);
        expect(roundToPsychologicalValue(1199)).toBe(1199);
        // …and the 1000 € boundary likewise: 999,99 € is still the 5 € grid,
        // 1000,00 € already the 50 € grid.
        expect(roundToPsychologicalValue(99999)).toBe(roundToPsychologicalValueInEuros(999.99) * CENTS_PER_EURO);
        expect(roundToPsychologicalValue(100000)).toBe(99900);
    });
});

describe('calculateShootingPrice', () => {
    it('defaults (90 min / 15 imgs, no flatrate, no discount) → 369,00 €', () => {
        expect(calculateShootingPrice(defaults())).toEqual({packagePrice: 36900, finalPrice: 36900, discountAbsolute: 0});
    });

    it('uses more images per hour (default 8) when isOutdoor is true', () => {
        // Base 50 + Time 120 + (Images (80/8)*15 = 150) = 320 -> gerundet auf 319
        expect(calculateShootingPrice(defaults({isOutdoor: true}))).toEqual({packagePrice: 31900, finalPrice: 31900, discountAbsolute: 0});
    });

    it('flatrate (+20%) → 445,00 €', () => {
        expect(calculateShootingPrice(defaults({flatrate: true}))).toEqual({packagePrice: 44500, finalPrice: 44500, discountAbsolute: 0});
    });

    it('33% discount → 369,00 € / 245,00 €', () => {
        expect(calculateShootingPrice(defaults({discount: '33'}))).toEqual({packagePrice: 36900, finalPrice: 24500, discountAbsolute: 12400});
    });

    it('50% discount → 369,00 € / 185,00 € (desired inexact rounding)', () => {
        expect(calculateShootingPrice(defaults({discount: '50'}))).toEqual({packagePrice: 36900, finalPrice: 18500, discountAbsolute: 18400});
    });

    it('honours a custom outdoor images-per-hour (20)', () => {
        // Base 50 + Time 120 + (Images (80/20)*15 = 60) = 230 -> gerundet auf 229
        expect(calculateShootingPrice(defaults({isOutdoor: true, calc_outdoor_images_per_hour: '20'}))).toEqual({packagePrice: 22900, finalPrice: 22900, discountAbsolute: 0});
    });

    it('outdoor images-per-hour 6 equals the indoor default (6)', () => {
        // Base 50 + Time 120 + (Images (80/6)*15 = 200) = 370 -> gerundet auf 369
        expect(calculateShootingPrice(defaults({isOutdoor: true, calc_outdoor_images_per_hour: '6'}))).toEqual({packagePrice: 36900, finalPrice: 36900, discountAbsolute: 0});
    });

    it('honours a custom base price', () => {
        expect(calculateShootingPrice(defaults({calc_base_price: 10000}))).toEqual({packagePrice: 41900, finalPrice: 41900, discountAbsolute: 0});
    });

    it('zero duration removes the time component → 249,00 €', () => {
        expect(calculateShootingPrice(defaults({duration: 0}))).toEqual({packagePrice: 24900, finalPrice: 24900, discountAbsolute: 0});
    });

    it('zero images removes the images component → 169,00 €', () => {
        expect(calculateShootingPrice(defaults({images: 0}))).toEqual({packagePrice: 16900, finalPrice: 16900, discountAbsolute: 0});
    });

    it('calc_images_per_hour "0" falls back to default 6 (no Infinity)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '0'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 36900, finalPrice: 36900, discountAbsolute: 0});
    });

    it('combines flatrate, outdoor and discount correctly', () => {
        // (Base 50 + Time 120 + (Images (80/8)*15 = 150)) * 1.2 = 320 * 1.2 = 384 -> gerundet auf 385
        // 385 - 50% = 192.5 -> gerundet auf 195
        expect(calculateShootingPrice(defaults({flatrate: true, isOutdoor: true, discount: '50'}))).toEqual({packagePrice: 38500, finalPrice: 19500, discountAbsolute: 19000});
    });

    it('honours a custom hourly rate', () => {
        expect(calculateShootingPrice(defaults({calc_hourly_rate: 20000}))).toEqual({packagePrice: 84900, finalPrice: 84900, discountAbsolute: 0});
    });

    it('honours a custom images-per-hour', () => {
        expect(calculateShootingPrice(defaults({calc_images_per_hour: '10'}))).toEqual({packagePrice: 28900, finalPrice: 28900, discountAbsolute: 0});
    });

    it('non-numeric calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: 'abc'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 36900, finalPrice: 36900, discountAbsolute: 0});
    });

    it('negative calc_images_per_hour falls back to default (finite result)', () => {
        const result = calculateShootingPrice(defaults({calc_images_per_hour: '-5'}));
        expect(Number.isFinite(result.packagePrice)).toBe(true);
        expect(Number.isFinite(result.finalPrice)).toBe(true);
        expect(result).toEqual({packagePrice: 36900, finalPrice: 36900, discountAbsolute: 0});
    });

    it('reorder skips the base price (calc_base_price = 0)', () => {
        // basePrice=0, time=120, images=200 → 320 → psych 319
        expect(calculateShootingPrice(defaults({isReorder: true}))).toEqual({packagePrice: 31900, finalPrice: 31900, discountAbsolute: 0});
    });

    it('reorder + outdoor uses outdoor images-per-hour on images', () => {
        // basePrice=0, time=120, images=(80/8)*15=150 → 270 → psych 269
        expect(calculateShootingPrice(defaults({isReorder: true, isOutdoor: true}))).toEqual({packagePrice: 26900, finalPrice: 26900, discountAbsolute: 0});
    });

    it('reorder + flatrate applies +20% on (time + images)', () => {
        // (0 + 120 + 200) * 1.2 = 384 → psych 385
        expect(calculateShootingPrice(defaults({isReorder: true, flatrate: true}))).toEqual({packagePrice: 38500, finalPrice: 38500, discountAbsolute: 0});
    });

    it('reorder + discount skips base price then applies discount', () => {
        // basePrice=0, time=120, images=200 → 320 → psych 319
        // 319 - 33% = 213.73 → psych 215
        expect(calculateShootingPrice(defaults({isReorder: true, discount: '33'}))).toEqual({packagePrice: 31900, finalPrice: 21500, discountAbsolute: 10400});
    });

    /**
     * The unit guard on the client side, in hand-checkable numbers.
     *
     * `isReorder` is the clean case: with the base price skipped, the total is
     * time price + images price, both driven by `calc_hourly_rate` alone, so
     * the pre-conversion answer (319 for 80 €/h) and the post-conversion one
     * (31 900 for 8000 cents/h) are the same money written with one more zero.
     *
     * The second half is the failure this conversion invites: a client, a
     * fixture or a settings row that still speaks the old euro contract is now
     * read as cents, and the price collapses by two orders of magnitude —
     * 3,20 € instead of 320,00 €. That is silent, it is what the backend
     * migration and the E2E unit guard exist to prevent, and it is pinned here
     * so the magnitude of it is written down.
     */
    it('reads its money inputs as cents, not euros', () => {
        expect(calculateShootingPrice(defaults({isReorder: true, calc_hourly_rate: 8000})).packagePrice).toBe(31900);
        expect(calculateShootingPrice(defaults({isReorder: true, calc_hourly_rate: 80})).packagePrice).toBe(320);

        // The seeded settings, read as the cents the API serves.
        expect(calculateShootingPrice(defaults()).packagePrice).toBe(100 * 369);
    });
});

describe('calculateShootingPrice is equivalent to the euro pipeline it replaced', () => {
    const cases: Array<[string, Partial<ShootingPriceInput>]> = [
        ['default', {}],
        ['outdoor', {isOutdoor: true}],
        ['flatrate', {flatrate: true}],
        ['33% discount', {discount: '33'}],
        ['50% discount', {discount: '50'}],
        ['outdoor 20/h', {isOutdoor: true, calc_outdoor_images_per_hour: '20'}],
        ['outdoor 6/h', {isOutdoor: true, calc_outdoor_images_per_hour: '6'}],
        ['flatrate + outdoor + 50%', {flatrate: true, isOutdoor: true, discount: '50'}],
        ['zero duration', {duration: 0}],
        ['zero images', {images: 0}],
        ['10 images/h', {calc_images_per_hour: '10'}],
        ['reorder', {isReorder: true}],
        ['reorder + outdoor', {isReorder: true, isOutdoor: true}],
        ['reorder + flatrate', {isReorder: true, flatrate: true}],
        ['reorder + 33%', {isReorder: true, discount: '33'}],
        ['reorder + 50%', {isReorder: true, discount: '50'}],
        ['flatrate + 33%', {flatrate: true, discount: '33'}],
    ];

    it.each(cases)('prices %s exactly as the euro pipeline did', (_name, overrides) => {
        const inCents = calculateShootingPrice(defaults(overrides));
        const inEuros = euroDefaults(overrides as Partial<Parameters<typeof calculateShootingPriceInEuros>[0]>);

        expect(inCents.packagePrice).toBe(inEuros.packagePrice * CENTS_PER_EURO);
        expect(inCents.finalPrice).toBe(inEuros.finalPrice * CENTS_PER_EURO);
        expect(inCents.discountAbsolute).toBe(inEuros.discountAbsolute * CENTS_PER_EURO);
    });

    it.each(cases)('prices %s identically for a money input that is a cent string', (_name, overrides) => {
        // The API also reaches the calculator as text (SWR cache, E2E helper),
        // so the string and the number form of the same amount must agree.
        const asNumbers = calculateShootingPrice(defaults(overrides));
        const asText = calculateShootingPrice(defaults({
            ...overrides,
            calc_base_price: String(overrides.calc_base_price ?? 5000),
            calc_hourly_rate: String(overrides.calc_hourly_rate ?? 8000),
        }));

        expect(asText).toEqual(asNumbers);
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
        expect(calculateB2CFlexPrice({ type: 'nude', setup: 'indoor', extraImages: 10, isFullyPrivate: true })).toEqual({ packagePrice: 549, finalPrice: 549, discountAbsolute: 0 });
    });
    /**
     * The flex calculator was not part of the cents conversion and still works
     * in euros. Pinned in both directions so a reader cannot assume it divides:
     * the modal is the one that turns the API's cents into the euros this
     * function takes (`ShootingCalculatorModal`, `srp_base_price:` branch).
     */
    it('takes euros, not the API cents — the caller does the division', () => {
        const base = {type: 'portrait', setup: 'outdoor', extraImages: 0, isFullyPrivate: false} as const;
        expect(calculateB2CFlexPrice({...base, srp_base_price: 14900 / CENTS_PER_EURO})).toEqual({packagePrice: 149, finalPrice: 149, discountAbsolute: 0});
        expect(calculateB2CFlexPrice({...base, srp_base_price: 14900})).toEqual({packagePrice: 14900, finalPrice: 14900, discountAbsolute: 0});
    });
});
