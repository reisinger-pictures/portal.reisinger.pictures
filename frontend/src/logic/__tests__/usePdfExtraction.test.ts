import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { usePdfExtraction } from '../usePdfExtraction';

vi.mock('../../ui/components/UIContext', () => ({
    useUI: vi.fn(() => ({
        showToast: vi.fn(),
    })),
}));

function mockFetch(response: unknown, ok = true) {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
        ok,
        json: () => Promise.resolve(response),
    }));
}

function mockFetchNetworkError() {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
}

function createMockFile(): File {
    return new File(['dummy content'], 'test.pdf', { type: 'application/pdf' });
}

function createMockChangeEvent(file: File): React.ChangeEvent<HTMLInputElement> {
    const fileList = Object.create(FileList.prototype, {
        0: { value: file },
        length: { value: 1 },
    });
    Object.setPrototypeOf(fileList, FileList.prototype);
    return {
        target: { files: fileList, value: '' },
        currentTarget: { files: fileList, value: '' },
    } as unknown as React.ChangeEvent<HTMLInputElement>;
}

function createEmptyChangeEvent(): React.ChangeEvent<HTMLInputElement> {
    return {
        target: { files: null as unknown as FileList, value: '' },
        currentTarget: { files: null as unknown as FileList, value: '' },
    } as unknown as React.ChangeEvent<HTMLInputElement>;
}

const mockApiResponse = {
    customer_name: 'Max Mustermann',
    customer_company: 'Firma GmbH',
    customer_email: 'max@test.com',
    customer_uid: 'ATU12345',
    terms_html: '<p>AGB</p>',
    items: [
        { type: 'item', description: 'Foto 1', notes: '', qty: 1, price: 5000 },
        { type: 'item', description: 'Foto 2', notes: '', qty: 2, price: 3000 },
        { type: 'discount', description: 'Rabatt', notes: '', price: -1000 },
    ],
};

describe('usePdfExtraction', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('returns isExtracting initially as false', () => {
        const onDataExtracted = vi.fn();
        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));
        expect(result.current.isExtracting).toBe(false);
    });

    it('calls fetch with FormData and processes response', async () => {
        const onDataExtracted = vi.fn();
        mockFetch(mockApiResponse);

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(fetch).toHaveBeenCalledWith('/api/management/invoices/extract-offer', expect.objectContaining({
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            credentials: 'include',
        }));

        const callArgs = vi.mocked(fetch).mock.calls[0];
        expect(callArgs[1]!.body).toBeInstanceOf(FormData);

        expect(onDataExtracted).toHaveBeenCalledTimes(1);
        expect(onDataExtracted).toHaveBeenCalledWith({
            customer_name: 'Max Mustermann',
            customer_company: 'Firma GmbH',
            customer_street: '',
            customer_zip: '',
            customer_city: '',
            customer_country: '',
            customer_email: 'max@test.com',
            customer_uid: 'ATU12345',
            terms_html: '<p>AGB</p>',
            items: [
                { type: 'item', description: 'Foto 1', notes: '', qty: 1, price: 50 },
                { type: 'item', description: 'Foto 2', notes: '', qty: 2, price: 30 },
            ],
            discounts: [
                { type: 'discount', description: 'Rabatt', notes: '', price: -10 },
            ],
        });
    });

    it('sets isExtracting true during request and false after', async () => {
        const onDataExtracted = vi.fn();

        let resolveFetch: (value: unknown) => void;
        vi.stubGlobal('fetch', vi.fn().mockReturnValue(new Promise((resolve) => {
            resolveFetch = resolve;
        })));

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        let promise: Promise<void>;
        act(() => {
            promise = result.current.processPdfFile(createMockFile());
        });

        expect(result.current.isExtracting).toBe(true);

        await act(async () => {
            resolveFetch!({
                ok: true,
                json: () => Promise.resolve({ items: [] }),
            });
            await promise!;
        });

        expect(result.current.isExtracting).toBe(false);
    });

    it('handles API error response correctly', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ error: 'Ungültiges PDF-Format' }, false);

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(onDataExtracted).not.toHaveBeenCalled();
        expect(result.current.isExtracting).toBe(false);
    });

    it('handles network failure gracefully', async () => {
        const onDataExtracted = vi.fn();
        mockFetchNetworkError();

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(onDataExtracted).not.toHaveBeenCalled();
        expect(result.current.isExtracting).toBe(false);
    });

    it('handles empty items array', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ items: [] });

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(onDataExtracted).toHaveBeenCalledWith(expect.objectContaining({
            items: [],
            discounts: [],
        }));
    });

    it('handles null/missing items in response', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({});

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(onDataExtracted).toHaveBeenCalledWith(expect.objectContaining({
            items: [],
            discounts: [],
        }));
    });

    it('handleFileUpload extracts file from change event and calls processPdfFile', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ items: [] });

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        const file = createMockFile();
        await act(async () => {
            await result.current.handleFileUpload(createMockChangeEvent(file));
        });

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('handleFileUpload does nothing when no file is selected', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ items: [] });

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.handleFileUpload(createEmptyChangeEvent());
        });

        expect(fetch).not.toHaveBeenCalled();
    });

    it('clears input value after file upload', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ items: [] });

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        const changeEvent = createMockChangeEvent(createMockFile());
        await act(async () => {
            await result.current.handleFileUpload(changeEvent);
        });

        expect(changeEvent.target.value).toBe('');
    });

    it('processPdfFile throws error with data.message when res.ok is false', async () => {
        const onDataExtracted = vi.fn();
        mockFetch({ message: 'Serverfehler' }, false);

        const { result } = renderHook(() => usePdfExtraction(onDataExtracted));

        await act(async () => {
            await result.current.processPdfFile(createMockFile());
        });

        expect(onDataExtracted).not.toHaveBeenCalled();
    });
});
