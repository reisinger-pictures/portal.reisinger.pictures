import {describe, it, expect} from 'vitest';
import {renderHook, act} from '@testing-library/react';
import {useInvoiceDraft, isEmptyRow} from '../useInvoiceDraft';
import {UIContext} from '../../ui/components/UIContext';
import type {ReactNode} from 'react';
import type {UIContextType} from '../../ui/components/UIContext';
import type {InvoiceItem, InvoiceDiscount} from '../../api';

const noopToast: UIContextType = {
    showToast: () => {},
    confirm: () => Promise.resolve(false),
    hasUnsavedChanges: false,
    setUnsavedChanges: () => {},
};

function createWrapper() {
    return function Wrapper({children}: {children: ReactNode}) {
        return (
            <UIContext.Provider value={noopToast}>
                {children}
            </UIContext.Provider>
        );
    };
}

const emptyRow: InvoiceItem = {type: 'item', description: '', notes: '', qty: 1, price: 0};
const filledRow: InvoiceItem = {type: 'item', description: 'Leistung A', notes: '', qty: 2, price: 5000};
const anotherFilledRow: InvoiceItem = {type: 'item', description: 'Leistung B', notes: 'Notiz', qty: 1, price: 3000};
const discountRow: InvoiceDiscount = {type: 'discount_fixed', description: 'Rabatt', notes: '', price: 1000};

// ---------------------------------------------------------------------------
// isEmptyRow pure helper
// ---------------------------------------------------------------------------
describe('isEmptyRow', () => {
    it('returns true for a default empty placeholder row', () => {
        expect(isEmptyRow(emptyRow)).toBe(true);
    });

    it('returns true when description has whitespace only', () => {
        expect(isEmptyRow({...emptyRow, description: '   '})).toBe(true);
    });

    it('returns true when notes has whitespace only', () => {
        expect(isEmptyRow({...emptyRow, notes: '   '})).toBe(true);
    });

    it('returns false when description is non-empty', () => {
        expect(isEmptyRow(filledRow)).toBe(false);
    });

    it('returns false when notes are non-empty', () => {
        expect(isEmptyRow({...emptyRow, notes: 'irgendwas'})).toBe(false);
    });

    it('returns false for a non-item type (e.g. discount)', () => {
        expect(isEmptyRow({type: 'discount_fixed', description: '', notes: '', qty: 1, price: 0} as InvoiceItem)).toBe(true);
    });

    it('returns false when qty differs from default 1', () => {
        expect(isEmptyRow({...emptyRow, qty: 2})).toBe(false);
    });

    it('returns false when price differs from default 0', () => {
        expect(isEmptyRow({...emptyRow, price: 100})).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// loadExtractedData
// ---------------------------------------------------------------------------
describe('loadExtractedData', () => {
    it('sets a single empty row when data.items is empty', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [],
                discounts: [],
            });
        });

        expect(result.current.items).toHaveLength(1);
        expect(isEmptyRow(result.current.items[0])).toBe(true);
    });

    it('filters out empty rows from data.items', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [emptyRow, filledRow, emptyRow, anotherFilledRow],
                discounts: [],
            });
        });

        // Both empty rows should be removed; only the two non-empty items remain
        expect(result.current.items).toHaveLength(2);
        expect(result.current.items[0]).toEqual(filledRow);
        expect(result.current.items[1]).toEqual(anotherFilledRow);
    });

    it('keeps normal (non-empty) items unchanged', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [filledRow, anotherFilledRow],
                discounts: [],
            });
        });

        expect(result.current.items).toHaveLength(2);
        expect(result.current.items[0]).toEqual(filledRow);
        expect(result.current.items[1]).toEqual(anotherFilledRow);
    });

    it('removes empty rows from a mixed items list', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [emptyRow, filledRow, emptyRow, emptyRow, anotherFilledRow, emptyRow],
                discounts: [],
            });
        });

        // Only the two non-empty items should survive
        expect(result.current.items).toHaveLength(2);
        expect(result.current.items[0]).toEqual(filledRow);
        expect(result.current.items[1]).toEqual(anotherFilledRow);
    });

    it('falls back to single empty row when all items are empty rows', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [emptyRow, {...emptyRow, description: '   '}, emptyRow],
                discounts: [],
            });
        });

        // All were empty → single empty fallback row
        expect(result.current.items).toHaveLength(1);
        expect(isEmptyRow(result.current.items[0])).toBe(true);
    });

    it('preserves discounts passed as data', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                items: [filledRow],
                discounts: [discountRow],
            });
        });

        expect(result.current.discounts).toHaveLength(1);
        expect(result.current.discounts[0]).toEqual(discountRow);
    });

    it('updates formData fields from extracted data', () => {
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});

        act(() => {
            result.current.loadExtractedData({
                customer_name: 'Max Mustermann',
                customer_company: 'Firma GmbH',
                customer_street: 'Musterstr. 1',
                customer_zip: '12345',
                customer_city: 'Musterstadt',
                customer_country: 'DE',
                customer_email: 'max@example.com',
                customer_uid: 'DE123456789',
                terms_html: '<p>Zahlungsbedingungen</p>',
                items: [filledRow],
                discounts: [],
            });
        });

        expect(result.current.formData.customer_name).toBe('Max Mustermann');
        expect(result.current.formData.customer_company).toBe('Firma GmbH');
        expect(result.current.formData.customer_street).toBe('Musterstr. 1');
        expect(result.current.formData.customer_zip).toBe('12345');
        expect(result.current.formData.customer_city).toBe('Musterstadt');
        expect(result.current.formData.customer_country).toBe('DE');
        expect(result.current.formData.customer_email).toBe('max@example.com');
        expect(result.current.formData.customer_uid).toBe('DE123456789');
        expect(result.current.formData.terms_html).toBe('<p>Zahlungsbedingungen</p>');
    });
});
