import type { InvoiceDiscount, InvoiceItem } from '../api';

export const CONTRACT_SNAPSHOT_SCALE = 100;
export const CONTRACT_PERCENT_SCALE = 10000;
export const MANUAL_QUANTITY_SCALE = 100;
export const CONTRACT_MAX_SAFE_INTEGER = Number.MAX_SAFE_INTEGER;

const MAX_SAFE_BIGINT = BigInt(CONTRACT_MAX_SAFE_INTEGER);
const MIN_SAFE_BIGINT = -MAX_SAFE_BIGINT;

export interface ContractWireItem {
    type: 'item';
    description: string;
    notes: string;
    qty: number;
    price: number;
}

export interface ContractWireDiscount {
    type: 'discount_fixed' | 'discount_percent';
    description: string;
    notes: string;
    price: number;
}

export type ContractWireLine = ContractWireItem | ContractWireDiscount;

/**
 * Manual-invoice wire metadata is accepted on every line. Discount pricing
 * deliberately ignores it; only item quantities are fixed-point inputs.
 */
export interface ManualInvoiceQuantityMetadata {
    qty: number;
    quantity_scale: typeof MANUAL_QUANTITY_SCALE;
}

export type ManualInvoiceWireLine =
    | (ContractWireItem & ManualInvoiceQuantityMetadata)
    | (ContractWireDiscount & ManualInvoiceQuantityMetadata);

export interface NormalizedContractSnapshot {
    items: ContractWireItem[];
    discounts: ContractWireDiscount[];
    lines: ContractWireLine[];
}

export interface EditorLine {
    type: string;
    description: string;
    notes: string;
    qty?: number;
    price: number;
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const isPositiveSafeInteger = (value: unknown): value is number =>
    typeof value === 'number'
    && Number.isSafeInteger(value)
    && value <= CONTRACT_MAX_SAFE_INTEGER
    && value >= 1;

const isContractLineType = (value: unknown): value is ContractWireLine['type'] =>
    value === 'item' || value === 'discount_fixed' || value === 'discount_percent';

const isContractDiscountType = (value: unknown): value is ContractWireDiscount['type'] =>
    value === 'discount_fixed' || value === 'discount_percent';

const invalidSnapshot = (): never => {
    throw new Error('Der Vertragsbetrag ist ungültig.');
};

const safeIntegerToBigInt = (value: number): bigint => {
    if (!Number.isSafeInteger(value) || value < -CONTRACT_MAX_SAFE_INTEGER || value > CONTRACT_MAX_SAFE_INTEGER) {
        invalidSnapshot();
    }

    return BigInt(value);
};

const bigintToSafeNumber = (value: bigint): number => {
    if (value < MIN_SAFE_BIGINT || value > MAX_SAFE_BIGINT) {
        invalidSnapshot();
    }

    return Number(value);
};

const parseLegacyIntegerString = (value: string): number | null => {
    if (!/^-?\d+$/.test(value)) return null;

    try {
        const parsed = BigInt(value);
        if (parsed < MIN_SAFE_BIGINT || parsed > MAX_SAFE_BIGINT) return null;
        return Number(parsed);
    } catch {
        return null;
    }
};

const normalizeWireInteger = (value: unknown, allowLegacyNumeric: boolean): number => {
    if (typeof value === 'number'
        && Number.isSafeInteger(value)
        && value <= CONTRACT_MAX_SAFE_INTEGER
        && value >= -CONTRACT_MAX_SAFE_INTEGER) {
        return value;
    }

    if (allowLegacyNumeric && typeof value === 'string') {
        const normalized = parseLegacyIntegerString(value);
        if (normalized !== null) return normalized;
    }

    return invalidSnapshot();
};

const normalizeWireLine = (
    value: unknown,
    source: 'items' | 'discounts',
    legacyRead: boolean,
): ContractWireLine => {
    if (!isRecord(value)) {
        return invalidSnapshot();
    }
    const record = value;

    const rawType = record.type;
    const type = rawType === undefined && legacyRead && source === 'items'
        ? 'item'
        : rawType;

    if (!isContractLineType(type)) {
        return invalidSnapshot();
    }
    if (typeof record.description !== 'string') {
        return invalidSnapshot();
    }

    const description = record.description;
    const notes = typeof record.notes === 'string' ? record.notes : '';
    const price = normalizeWireInteger(record.price, legacyRead);
    if (price < 0) return invalidSnapshot();

    if (type === 'item') {
        const rawQty = record.qty === undefined && legacyRead ? 1 : record.qty;
        const qty = normalizeWireInteger(rawQty, legacyRead);
        if (!isPositiveSafeInteger(qty)) {
            return invalidSnapshot();
        }
        return { type, description, notes, qty, price };
    }

    return { type, description, notes, price };
};

const normalizeWireLines = (
    value: unknown,
    source: 'items' | 'discounts',
    legacyRead: boolean,
): ContractWireLine[] => {
    if (value === null || value === undefined) return [];
    if (!Array.isArray(value)) {
        return invalidSnapshot();
    }

    return Array.from(value, (line: unknown) => normalizeWireLine(line, source, legacyRead));
};

const hasMixedPlacement = (
    items: readonly ContractWireLine[],
    discounts: readonly ContractWireLine[],
): boolean =>
    items.some((line) => line.type !== 'item') || discounts.some((line) => line.type === 'item');

/**
 * Normalize a wire snapshot for reads. Mixed placement is accepted only as
 * the explicit legacy compatibility path; new writes use
 * normalizeContractSnapshotForWrite and reject it.
 */
export function normalizeContractSnapshot(
    items: unknown,
    discounts: unknown,
): NormalizedContractSnapshot {
    const itemLines = normalizeWireLines(items, 'items', true);
    const discountLines = normalizeWireLines(discounts, 'discounts', true);
    const normalizedItems = [
        ...itemLines.filter((line): line is ContractWireItem => line.type === 'item'),
        ...discountLines.filter((line): line is ContractWireItem => line.type === 'item'),
    ];
    const normalizedDiscounts = [
        ...itemLines.filter((line): line is ContractWireDiscount => line.type !== 'item'),
        ...discountLines.filter((line): line is ContractWireDiscount => line.type !== 'item'),
    ];

    return {
        items: normalizedItems,
        discounts: normalizedDiscounts,
        lines: hasMixedPlacement(itemLines, discountLines)
            ? [...itemLines, ...discountLines]
            : [...normalizedItems, ...normalizedDiscounts],
    };
}

export function normalizeContractSnapshotForWrite(
    items: unknown,
    discounts: unknown,
): NormalizedContractSnapshot {
    const itemLines = normalizeWireLines(items, 'items', false);
    const discountLines = normalizeWireLines(discounts, 'discounts', false);

    if (itemLines.some((line) => line.type !== 'item')) invalidSnapshot();
    if (discountLines.some((line) => line.type === 'item')) invalidSnapshot();

    return {
        items: itemLines.filter((line): line is ContractWireItem => line.type === 'item'),
        discounts: discountLines.filter((line): line is ContractWireDiscount => line.type !== 'item'),
        lines: [...itemLines, ...discountLines],
    };
}

/**
 * Convert a bounded editor major-unit number to a fixed-point integer.
 * Precision is checked before the deliberate two-decimal Math.round step;
 * wire normalization and pricing arithmetic remain strict integer/BigInt code.
 */
const decimalNumberToScaledUnits = (
    value: number,
    scale: number,
    requirePositive: boolean,
): number => {
    if (typeof value !== 'number' || !Number.isFinite(value)
        || !Number.isSafeInteger(scale) || scale < 1 || value < 0) {
        return invalidSnapshot();
    }

    const scaleText = String(scale);
    const scaleDecimalPlaces = scaleText.length - 1;
    if (10n ** BigInt(scaleDecimalPlaces) !== BigInt(scale)) return invalidSnapshot();

    // Editor values are a deliberately bounded two-decimal surface. Validate
    // the displayed precision first, then use bounded Math.round conversion so
    // the binary representation of a max-safe cents value (for example
    // 90071992547409.9) round-trips to the original wire integer instead of
    // losing one cent during string-based re-scaling.
    const match = /^([+]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(String(value));
    if (!match) return invalidSnapshot();

    const fraction = match[3] ?? '';
    const exponent = Number(match[4] ?? '0');
    if (!Number.isSafeInteger(exponent) || Math.abs(exponent) > 1000) {
        return invalidSnapshot();
    }

    const displayedDecimalPlaces = fraction.length - exponent;
    if (displayedDecimalPlaces > scaleDecimalPlaces) return invalidSnapshot();

    const maximumMajorUnits = Number(MAX_SAFE_BIGINT) / scale;
    if (value > maximumMajorUnits) return invalidSnapshot();

    const scaled = value * scale;
    if (!Number.isFinite(scaled)) return invalidSnapshot();

    const units = Math.round(scaled);
    if (!Number.isSafeInteger(units) || units < 0 || units > CONTRACT_MAX_SAFE_INTEGER
        || (requirePositive && units < 1)
    ) {
        return invalidSnapshot();
    }

    return units;
};

const toSnapshotValue = (value: number): number =>
    decimalNumberToScaledUnits(value, CONTRACT_SNAPSHOT_SCALE, false);

const toManualQuantityUnits = (value: number | undefined): number => {
    if (typeof value !== 'number') return invalidSnapshot();
    return decimalNumberToScaledUnits(value, MANUAL_QUANTITY_SCALE, true);
};

/** Convert a safe fixed-point integer to a major-unit number without arithmetic rounding. */
export function fixedPointToMajorUnits(value: number, scale: number = CONTRACT_SNAPSHOT_SCALE): number {
    if (!Number.isSafeInteger(scale) || scale < 1) return invalidSnapshot();
    const scaleText = String(scale);
    if (scaleText !== '100' && scaleText !== '10000') return invalidSnapshot();

    const signedUnits = safeIntegerToBigInt(value);
    const sign = signedUnits < 0n ? '-' : '';
    const units = signedUnits < 0n ? -signedUnits : signedUnits;
    const divisor = 10n ** BigInt(scaleText.length - 1);
    const whole = units / divisor;
    const fraction = (units % divisor).toString().padStart(scaleText.length - 1, '0').replace(/0+$/, '');
    const decimal = fraction === '' ? whole.toString() : `${whole}.${fraction}`;
    const result = Number(`${sign}${decimal}`);
    if (!Number.isFinite(result)) return invalidSnapshot();
    return result;
}

/** Format basis points as a human-readable percentage without float division. */
export function formatBasisPointsAsPercent(basisPoints: number): string {
    const units = safeIntegerToBigInt(basisPoints);
    const sign = units < 0n ? '-' : '';
    const absolute = units < 0n ? -units : units;
    const whole = absolute / 100n;
    const fraction = (absolute % 100n).toString().padStart(2, '0').replace(/0+$/, '');
    return `${sign}${whole}${fraction === '' ? '' : `.${fraction}`}%`;
}

const checkedAdd = (left: number, right: number): number => {
    const result = safeIntegerToBigInt(left) + safeIntegerToBigInt(right);
    return bigintToSafeNumber(result);
};

const checkedAddBig = (left: bigint, right: bigint): bigint => {
    const result = left + right;
    if (result < MIN_SAFE_BIGINT || result > MAX_SAFE_BIGINT) invalidSnapshot();
    return result;
};

const checkedMultiplyNonNegative = (left: number, right: number): number => {
    if (!Number.isInteger(left) || !Number.isInteger(right) || left < 0 || right < 0) {
        invalidSnapshot();
    }
    return bigintToSafeNumber(safeIntegerToBigInt(left) * safeIntegerToBigInt(right));
};

const multiplyAndRoundHalfUpNonNegativeBig = (
    left: number,
    right: number,
    scale: number,
): bigint => {
    if (!Number.isInteger(left) || !Number.isInteger(right)
        || left < 0 || right < 0 || !Number.isInteger(scale) || scale < 1) {
        invalidSnapshot();
    }

    const leftBig = safeIntegerToBigInt(left);
    const rightBig = safeIntegerToBigInt(right);
    const scaleBig = safeIntegerToBigInt(scale);
    const leftQuotient = leftBig / scaleBig;
    const leftRemainder = leftBig % scaleBig;
    const rightQuotient = rightBig / scaleBig;
    const rightRemainder = rightBig % scaleBig;

    const wholeAmount = checkedAddBig(
        checkedAddBig(
            leftQuotient * rightQuotient * scaleBig,
            leftQuotient * rightRemainder,
        ),
        0n,
    );
    const remainderAmount = leftRemainder * rightQuotient;
    const smallProduct = leftRemainder * rightRemainder;
    const roundedRemainder = smallProduct / scaleBig
        + (smallProduct % scaleBig >= (scaleBig + 1n) / 2n ? 1n : 0n);

    return checkedAddBig(
        checkedAddBig(wholeAmount, remainderAmount),
        roundedRemainder,
    );
};

const multiplyAndRoundHalfUpNonNegative = (
    left: number,
    right: number,
    scale: number,
): number => bigintToSafeNumber(multiplyAndRoundHalfUpNonNegativeBig(left, right, scale));

/** Calculate an ordered percentage deduction as a safe integer. */
const percentageDiscountAmount = (runningTotal: number, rate: number): number => {
    const running = safeIntegerToBigInt(runningTotal);
    const absolute = running < 0n ? -running : running;
    const amount = multiplyAndRoundHalfUpNonNegativeBig(
        bigintToSafeNumber(absolute),
        rate,
        CONTRACT_PERCENT_SCALE,
    );
    return bigintToSafeNumber(running < 0n ? -amount : amount);
};

const editorLineToWire = (line: EditorLine, expectedType: 'item' | 'discount'): ContractWireLine => {
    if (typeof line.description !== 'string' || typeof line.notes !== 'string') invalidSnapshot();

    const price = toSnapshotValue(line.price);
    if (expectedType === 'item') {
        if (line.type !== 'item') invalidSnapshot();
        const qty = line.qty;
        if (!isPositiveSafeInteger(qty)) return invalidSnapshot();
        return { type: 'item', description: line.description, notes: line.notes, qty, price };
    }

    if (!isContractDiscountType(line.type)) return invalidSnapshot();
    return { type: line.type, description: line.description, notes: line.notes, price };
};

const editorItemToWire = (line: EditorLine): ContractWireItem => {
    const wireLine = editorLineToWire(line, 'item');
    if (wireLine.type !== 'item') return invalidSnapshot();
    return wireLine;
};

const editorItemToManualWire = (line: EditorLine): ManualInvoiceWireLine => {
    if (line.type !== 'item'
        || typeof line.description !== 'string'
        || typeof line.notes !== 'string') {
        return invalidSnapshot();
    }

    return {
        type: 'item',
        description: line.description,
        notes: line.notes,
        qty: toManualQuantityUnits(line.qty),
        price: toSnapshotValue(line.price),
        quantity_scale: MANUAL_QUANTITY_SCALE,
    };
};

const editorDiscountToManualWire = (line: EditorLine): ManualInvoiceWireLine => {
    const wireLine = editorLineToWire(line, 'discount');
    if (!isContractDiscountType(wireLine.type)) return invalidSnapshot();
    return {
        type: wireLine.type,
        description: wireLine.description,
        notes: wireLine.notes,
        price: wireLine.price,
        qty: MANUAL_QUANTITY_SCALE,
        quantity_scale: MANUAL_QUANTITY_SCALE,
    };
};

/** Serialize the separate management-editor arrays into the contract wire shape. */
export function serializeContractSnapshot(
    items: readonly InvoiceItem[],
    discounts: readonly InvoiceDiscount[],
): NormalizedContractSnapshot {
    const wireItems = items.map(editorItemToWire);
    const wireDiscounts = discounts.map((line) => editorLineToWire(line, 'discount'));
    return normalizeContractSnapshotForWrite(wireItems, wireDiscounts);
}

/** Serialize manual invoices to their ordered combined line-item wire shape. */
export function serializeManualInvoiceLines(
    items: readonly InvoiceItem[],
    discounts: readonly InvoiceDiscount[],
): ManualInvoiceWireLine[] {
    return [
        ...items.map(editorItemToManualWire),
        ...discounts.map(editorDiscountToManualWire),
    ];
}

export function calculateWireLineTotal(lines: readonly ContractWireLine[]): number {
    let runningTotal = 0;
    const normalizedLines = lines.map((line) => normalizeWireLine(line, 'items', true));

    for (const line of normalizedLines) {
        if (line.type === 'item') {
            runningTotal = checkedAdd(
                runningTotal,
                checkedMultiplyNonNegative(line.price, line.qty),
            );
        } else if (line.type === 'discount_fixed') {
            runningTotal = checkedAdd(runningTotal, -line.price);
        } else {
            const discountAmount = percentageDiscountAmount(runningTotal, line.price);
            runningTotal = checkedAdd(runningTotal, -discountAmount);
        }
    }

    return Math.max(0, runningTotal);
}

export function calculateManualInvoiceWireLineTotal(
    lines: readonly ManualInvoiceWireLine[],
): number {
    let runningTotal = 0;
    const normalizedLines = lines.map((line): ManualInvoiceWireLine => {
        if (line.quantity_scale !== MANUAL_QUANTITY_SCALE) return invalidSnapshot();

        const normalized = normalizeWireLine(line, 'items', false);
        if (normalized.type === 'item') {
            return {
                ...normalized,
                quantity_scale: MANUAL_QUANTITY_SCALE,
            };
        }
        return {
            ...normalized,
            qty: MANUAL_QUANTITY_SCALE,
            quantity_scale: MANUAL_QUANTITY_SCALE,
        };
    });

    for (const line of normalizedLines) {
        if (line.type === 'item') {
            runningTotal = checkedAdd(
                runningTotal,
                multiplyAndRoundHalfUpNonNegative(
                    line.price,
                    line.qty,
                    MANUAL_QUANTITY_SCALE,
                ),
            );
        } else if (line.type === 'discount_fixed') {
            runningTotal = checkedAdd(runningTotal, -line.price);
        } else {
            const discountAmount = percentageDiscountAmount(runningTotal, line.price);
            runningTotal = checkedAdd(runningTotal, -discountAmount);
        }
    }

    return Math.max(0, runningTotal);
}

export function calculateContractTotal(snapshot: NormalizedContractSnapshot): number {
    return calculateWireLineTotal(snapshot.lines);
}

export function calculateEditorContractTotal(
    items: readonly InvoiceItem[],
    discounts: readonly InvoiceDiscount[],
): number {
    return calculateContractTotal(serializeContractSnapshot(items, discounts));
}

export function calculateEditorInvoiceTotal(
    items: readonly InvoiceItem[],
    discounts: readonly InvoiceDiscount[],
): number {
    return calculateManualInvoiceWireLineTotal(serializeManualInvoiceLines(items, discounts));
}

export function calculateEditorSubtotal(items: readonly InvoiceItem[]): number {
    const wireItems = items.map(editorItemToWire);
    return wireItems.reduce(
        (sum, line) => checkedAdd(
            sum,
            checkedMultiplyNonNegative(line.price, line.qty),
        ),
        0,
    );
}

export function calculateEditorManualInvoiceSubtotal(items: readonly InvoiceItem[]): number {
    return items
        .map(editorItemToManualWire)
        .reduce(
            (sum, line) => checkedAdd(
                sum,
                multiplyAndRoundHalfUpNonNegative(
                    line.price,
                    line.qty,
                    MANUAL_QUANTITY_SCALE,
                ),
            ),
            0,
        );
}

/** Return one exact editor row total; invalid transient rows render as zero. */
export function calculateEditorItemTotal(
    item: InvoiceItem,
    mode: 'contract' | 'manual' = 'manual',
): number {
    try {
        return mode === 'contract'
            ? calculateEditorSubtotal([item])
            : calculateEditorManualInvoiceSubtotal([item]);
    } catch {
        return 0;
    }
}

/** Return the amount of each ordered discount in editor major units. */
export function calculateEditorDiscountAmounts(
    subtotal: number,
    discounts: readonly InvoiceDiscount[],
): number[] {
    let runningTotal = toSnapshotValue(subtotal);
    const amounts: number[] = [];

    for (const discount of discounts) {
        const wireDiscount = editorLineToWire(discount, 'discount');
        const amount = wireDiscount.type === 'discount_fixed'
            ? wireDiscount.price
            : percentageDiscountAmount(runningTotal, wireDiscount.price);
        amounts.push(fixedPointToMajorUnits(amount));
        runningTotal = checkedAdd(runningTotal, -amount);
    }

    return amounts;
}
