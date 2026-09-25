import {afterEach, describe, it, expect, vi} from 'vitest';
import {renderHook, act} from '@testing-library/react';
import {useInvoiceDraft, isEmptyRow} from '../useInvoiceDraft';
import {UIContext} from '../../ui/components/UIContext';
import {StrictMode, type FormEvent, type ReactNode} from 'react';
import type {UIContextType} from '../../ui/components/UIContext';
import {apiDownload, type InvoiceItem, type InvoiceDiscount} from '../../api';

vi.mock('../../api', async importOriginal => {
    const actual = await importOriginal<typeof import('../../api')>();
    return {...actual, apiDownload: vi.fn()};
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

const noopToast: UIContextType = {
    showToast: () => {},
    confirm: () => Promise.resolve(false),
    hasUnsavedChanges: false,
    setUnsavedChanges: () => {},
};

function createWrapper(value: UIContextType = noopToast) {
    return function Wrapper({children}: {children: ReactNode}) {
        return (
            <UIContext.Provider value={value}>
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

describe('manual invoice serialization', () => {
    it('sends a quarter quantity as explicit hundredths', async () => {
        vi.mocked(apiDownload).mockResolvedValue({
            blob: new Blob(['invoice'], {type: 'application/pdf'}),
            filename: null,
        });
        vi.stubGlobal('URL', {createObjectURL: vi.fn(() => 'blob:invoice')});
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper: createWrapper()});
        act(() => {
            result.current.loadExtractedData({
                items: [{...filledRow, qty: 0.25}],
                discounts: [],
            });
        });

        const event = {preventDefault: vi.fn()} as unknown as FormEvent;
        await act(async () => {
            await result.current.handleDownload(event);
        });

        const request = vi.mocked(apiDownload).mock.calls[0]?.[1];
        if (!request || typeof request.body !== 'string') {
            throw new Error('Manual invoice request body was not captured.');
        }
        const payload = JSON.parse(request.body) as {items: Array<{qty: number; quantity_scale: number}>};
        expect(payload.items[0]).toEqual(expect.objectContaining({
            qty: 25,
            quantity_scale: 100,
        }));
    });
});

describe('item move dirty state', () => {
    it('marks a clean item move only once in StrictMode', async () => {
        vi.mocked(apiDownload).mockResolvedValue({
            blob: new Blob(['invoice'], {type: 'application/pdf'}),
            filename: null,
        });
        vi.stubGlobal('URL', {createObjectURL: vi.fn(() => 'blob:invoice')});
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        const setUnsavedChanges = vi.fn();
        const value: UIContextType = {...noopToast, setUnsavedChanges};
        const wrapper = ({children}: {children: ReactNode}) => (
            <StrictMode>
                <UIContext.Provider value={value}>{children}</UIContext.Provider>
            </StrictMode>
        );
        const {result} = renderHook(() => useInvoiceDraft('invoice'), {wrapper});

        act(() => {
            result.current.loadExtractedData({items: [filledRow, anotherFilledRow], discounts: []});
        });
        // The successful download is the public clean-state reset; the move below is the
        // first dirty operation after that reset.
        const event = {preventDefault: vi.fn()} as unknown as FormEvent;
        await act(async () => {
            await result.current.handleDownload(event);
        });

        expect(result.current.items).toEqual([filledRow, anotherFilledRow]);
        expect(result.current.isDirty).toBe(false);
        setUnsavedChanges.mockClear();

        act(() => { result.current.moveItemDown(0); });

        expect(result.current.items).toEqual([anotherFilledRow, filledRow]);
        expect(result.current.isDirty).toBe(true);
        expect(setUnsavedChanges).toHaveBeenCalledTimes(1);
        expect(setUnsavedChanges).toHaveBeenCalledWith(true);
    });
});
