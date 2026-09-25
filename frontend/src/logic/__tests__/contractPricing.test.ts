import { describe, expect, it } from 'vitest';
import type {InvoiceDiscount, InvoiceItem} from '../../api';
import type {ManualInvoiceWireLine} from '../contractPricing';
import {
    calculateEditorContractTotal,
    calculateEditorDiscountAmounts,
    calculateEditorInvoiceTotal,
    calculateEditorItemTotal,
    calculateEditorManualInvoiceSubtotal,
    calculateManualInvoiceWireLineTotal,
    calculateWireLineTotal,
    fixedPointToMajorUnits,
    formatBasisPointsAsPercent,
    CONTRACT_MAX_SAFE_INTEGER,
    MANUAL_QUANTITY_SCALE,
    normalizeContractSnapshot,
    normalizeContractSnapshotForWrite,
    serializeContractSnapshot,
    serializeManualInvoiceLines,
} from '../contractPricing';

const item = (price: number, qty = 1): InvoiceItem => ({
    type: 'item',
    description: 'Leistung',
    notes: '',
    qty,
    price,
});

const discount = (type: 'discount_fixed' | 'discount_percent', price: number): InvoiceDiscount => ({
    type,
    description: 'Rabatt',
    notes: '',
    price,
});

describe('authoritative contract pricing', () => {
    it('applies ordered fixed and percentage discounts to integer cents', () => {
        const total = calculateEditorContractTotal(
            [item(100, 2)],
            [discount('discount_percent', 10), discount('discount_fixed', 5.5)],
        );

        expect(total).toBe(17450);
        expect(calculateEditorInvoiceTotal(
            [item(100, 2)],
            [discount('discount_percent', 10), discount('discount_fixed', 5.5)],
        )).toBe(total);
    });

    it('ignores a stale row_total when calculating a snapshot', () => {
        const snapshot = normalizeContractSnapshot(
            [{ type: 'item', description: 'Leistung', notes: '', qty: 2, price: 5000, row_total: 1 }],
            [],
        );

        expect(calculateEditorContractTotal([], [])).toBe(0);
        expect(snapshot.lines[0]).toEqual({
            type: 'item',
            description: 'Leistung',
            notes: '',
            qty: 2,
            price: 5000,
        });
    });

    it('normalizes legacy mixed placement while retaining source line order', () => {
        const snapshot = normalizeContractSnapshot(
            [
                { type: 'item', description: 'Leistung', notes: '', qty: 1, price: 10000 },
                { type: 'discount_percent', description: '10%', notes: '', price: 1000 },
            ],
            [{ type: 'discount_fixed', description: 'Bonus', notes: '', price: 500 }],
        );

        expect(snapshot.items).toHaveLength(1);
        expect(snapshot.discounts.map((line) => line.type)).toEqual([
            'discount_percent',
            'discount_fixed',
        ]);
        expect(snapshot.lines.map((line) => line.type)).toEqual([
            'item',
            'discount_percent',
            'discount_fixed',
        ]);
        expect(calculateEditorContractTotal([], [])).toBe(0);
        expect(calculateWireLineTotal(snapshot.lines)).toBe(8500);
    });

    it('rejects mixed placement for new contract snapshots', () => {
        expect(() => serializeContractSnapshot(
            [{ ...item(10), type: 'discount_percent' }],
            [],
        )).toThrow('Der Vertragsbetrag ist ungültig.');
    });

    it('serializes manual quantities as explicit hundredths and keeps ordered discounts', () => {
        const lines = serializeManualInvoiceLines(
            [item(100, 2)],
            [discount('discount_percent', 10), discount('discount_fixed', 5.5)],
        );

        expect(lines).toEqual([
            {
                type: 'item',
                description: 'Leistung',
                notes: '',
                qty: 200,
                quantity_scale: MANUAL_QUANTITY_SCALE,
                price: 10000,
            },
            {
                type: 'discount_percent',
                description: 'Rabatt',
                notes: '',
                price: 1000,
                qty: MANUAL_QUANTITY_SCALE,
                quantity_scale: MANUAL_QUANTITY_SCALE,
            },
            {
                type: 'discount_fixed',
                description: 'Rabatt',
                notes: '',
                price: 550,
                qty: MANUAL_QUANTITY_SCALE,
                quantity_scale: MANUAL_QUANTITY_SCALE,
            },
        ]);
        expect(calculateEditorDiscountAmounts(200, [
            discount('discount_percent', 10),
            discount('discount_fixed', 5.5),
        ])).toEqual([20, 5.5]);
    });

    it('accepts fixed-point metadata on discount lines without using it for pricing', () => {
        const lines: ManualInvoiceWireLine[] = [
            {
                type: 'item',
                description: 'Leistung',
                notes: '',
                qty: 25,
                quantity_scale: MANUAL_QUANTITY_SCALE,
                price: 1000,
            },
            {
                type: 'discount_percent',
                description: '10% Rabatt',
                notes: '',
                qty: 1,
                quantity_scale: MANUAL_QUANTITY_SCALE,
                price: 1000,
            },
            {
                type: 'discount_fixed',
                description: 'Rabatt',
                notes: '',
                qty: 999,
                quantity_scale: MANUAL_QUANTITY_SCALE,
                price: 25,
            },
        ];

        expect(calculateManualInvoiceWireLineTotal(lines)).toBe(200);
    });

    it('preserves a 0.25 manual-invoice quantity through fixed-point pricing', () => {
        const lines = serializeManualInvoiceLines([item(10, 0.25)], []);

        expect(lines).toEqual([{
            type: 'item',
            description: 'Leistung',
            notes: '',
            qty: 25,
            quantity_scale: MANUAL_QUANTITY_SCALE,
            price: 1000,
        }]);
        expect(calculateEditorInvoiceTotal([item(10, 0.25)], [])).toBe(250);
        expect(calculateEditorManualInvoiceSubtotal([item(10, 0.25)])).toBe(250);
        expect(() => serializeManualInvoiceLines([item(10, 0.125)], []))
            .toThrow('Der Vertragsbetrag ist ungültig.');
    });

    it('keeps editor decimal conversion exact and distinguishes manual from contract quantities', () => {
        expect(serializeContractSnapshot([item(10.01)], [discount('discount_percent', 33.33)])).toEqual({
            items: [{
                type: 'item',
                description: 'Leistung',
                notes: '',
                qty: 1,
                price: 1001,
            }],
            discounts: [{
                type: 'discount_percent',
                description: 'Rabatt',
                notes: '',
                price: 3333,
            }],
            lines: [
                {
                    type: 'item',
                    description: 'Leistung',
                    notes: '',
                    qty: 1,
                    price: 1001,
                },
                {
                    type: 'discount_percent',
                    description: 'Rabatt',
                    notes: '',
                    price: 3333,
                },
            ],
        });
        expect(calculateEditorItemTotal(item(10, 0.25), 'manual')).toBe(250);
        expect(calculateEditorItemTotal(item(10, 0.25), 'contract')).toBe(0);
        expect(formatBasisPointsAsPercent(1000)).toBe('10%');
        expect(formatBasisPointsAsPercent(1001)).toBe('10.01%');
    });

    it('uses the JavaScript safe-integer boundary for canonical writes and bounded legacy reads', () => {
        expect(CONTRACT_MAX_SAFE_INTEGER).toBe(Number.MAX_SAFE_INTEGER);

        const canonical = normalizeContractSnapshotForWrite([{
            type: 'item',
            description: 'Leistung',
            notes: '',
            qty: 1,
            price: CONTRACT_MAX_SAFE_INTEGER,
        }], []);
        expect(canonical.items[0].price).toBe(CONTRACT_MAX_SAFE_INTEGER);

        const legacy = normalizeContractSnapshot([{
            type: 'item',
            description: 'Legacy',
            notes: '',
            qty: '2',
            price: '0010000',
        }], [{
            type: 'discount_percent',
            description: '10%',
            notes: '',
            price: 1000,
        }]);
        expect(legacy.items[0]).toEqual({
            type: 'item',
            description: 'Legacy',
            notes: '',
            qty: 2,
            price: 10000,
        });
        expect(legacy.discounts[0].price).toBe(1000);

        expect(() => normalizeContractSnapshotForWrite([{
            type: 'item',
            description: 'Nicht kanonisch',
            notes: '',
            qty: 1,
            price: '1000',
        }], [])).toThrow('Der Vertragsbetrag ist ungültig.');
        expect(() => normalizeContractSnapshot([{
            type: 'item',
            description: 'Zu groß',
            notes: '',
            qty: 1,
            price: String(CONTRACT_MAX_SAFE_INTEGER + 1),
        }], [])).toThrow('Der Vertragsbetrag ist ungültig.');
        expect(() => normalizeContractSnapshot([{
            type: 'item',
            description: 'Dezimal',
            notes: '',
            qty: 1,
            price: 1.5,
        }], [])).toThrow('Der Vertragsbetrag ist ungültig.');
        expect(() => normalizeContractSnapshot([{
            type: 'item',
            description: 'Unsicherer Float',
            notes: '',
            qty: 1,
            price: CONTRACT_MAX_SAFE_INTEGER + 1,
        }], [])).toThrow('Der Vertragsbetrag ist ungültig.');
    });

    it('keeps ordered percentage rounding half-up and integer-only', () => {
        expect(calculateWireLineTotal([
            { type: 'item', description: 'A', notes: '', qty: 1, price: 101 },
            { type: 'discount_percent', description: '25%', notes: '', price: 2500 },
            { type: 'item', description: 'B', notes: '', qty: 1, price: 2 },
            { type: 'discount_percent', description: '25%', notes: '', price: 2500 },
        ])).toBe(58);

        expect(calculateWireLineTotal([
            { type: 'item', description: 'Max', notes: '', qty: 1, price: CONTRACT_MAX_SAFE_INTEGER },
            { type: 'discount_percent', description: '100%', notes: '', price: 10000 },
        ])).toBe(0);
    });

    it('round-trips max-safe editor values for item, fixed/percent discounts, and manual quantity', () => {
        const maximum = CONTRACT_MAX_SAFE_INTEGER;
        const maximumMajorUnits = fixedPointToMajorUnits(maximum);

        expect(serializeContractSnapshot([item(maximumMajorUnits)], []).items[0].price).toBe(maximum);
        expect(serializeContractSnapshot(
            [],
            [discount('discount_fixed', maximumMajorUnits)],
        ).discounts[0].price).toBe(maximum);
        expect(serializeContractSnapshot(
            [],
            [discount('discount_percent', maximumMajorUnits)],
        ).discounts[0].price).toBe(maximum);

        const manualLines = serializeManualInvoiceLines([
            item(1, fixedPointToMajorUnits(maximum, MANUAL_QUANTITY_SCALE)),
        ], []);
        expect(manualLines[0].qty).toBe(maximum);
        expect(manualLines[0].quantity_scale).toBe(MANUAL_QUANTITY_SCALE);
    });

    it('accepts exact fractional calculated_percentage display strings in the API type', () => {
        const fractionalDisplay: InvoiceDiscount = {
            type: 'discount_percent',
            description: 'Bruchprozent',
            notes: '',
            price: 33.33,
            calculated_percentage: '33.33',
        };

        expect(serializeContractSnapshot([], [fractionalDisplay]).discounts[0].price).toBe(3333);
    });

    it('rejects line, running-total, and percentage overflow before unsafe arithmetic', () => {
        expect(() => calculateWireLineTotal([
            { type: 'item', description: 'Produkt', notes: '', qty: 2, price: CONTRACT_MAX_SAFE_INTEGER },
        ])).toThrow('Der Vertragsbetrag ist ungültig.');

        expect(() => calculateWireLineTotal([
            { type: 'item', description: 'Summenlauf 1', notes: '', qty: 1, price: CONTRACT_MAX_SAFE_INTEGER },
            { type: 'item', description: 'Summenlauf 2', notes: '', qty: 1, price: 1 },
        ])).toThrow('Der Vertragsbetrag ist ungültig.');

        expect(() => calculateWireLineTotal([
            { type: 'item', description: 'Max', notes: '', qty: 1, price: CONTRACT_MAX_SAFE_INTEGER },
            {
                type: 'discount_percent',
                description: 'Überlauf',
                notes: '',
                price: CONTRACT_MAX_SAFE_INTEGER,
            },
        ])).toThrow('Der Vertragsbetrag ist ungültig.');
    });
});
