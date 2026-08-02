import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import type { DragEvent } from 'react';
import { useProjectPdfDrop } from '../useProjectPdfDrop';

const { showToastMock } = vi.hoisted(() => ({ showToastMock: vi.fn() }));

vi.mock('../../ui/components/UIContext', () => ({
    useUI: () => ({ showToast: showToastMock }),
}));

function dropEvent(file: File): DragEvent {
    return {
        preventDefault: vi.fn(),
        dataTransfer: { files: [file] },
    } as unknown as DragEvent;
}

function mockFetchResponse(response: unknown, ok = true) {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
        ok,
        json: () => Promise.resolve(response),
    }));
}

describe('useProjectPdfDrop', () => {
    beforeEach(() => {
        showToastMock.mockClear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('extracts data and calls onExtracted for a valid pdf', async () => {
        const onExtracted = vi.fn();
        mockFetchResponse({ customer_name: 'Max', customer_email: 'max@x.de', items: [] });

        const { result } = renderHook(() => useProjectPdfDrop(onExtracted));
        const file = new File(['pdf'], 'offer.pdf', { type: 'application/pdf' });

        await act(async () => {
            await result.current.handleDrop(dropEvent(file));
        });

        expect(onExtracted).toHaveBeenCalledWith(expect.objectContaining({
            customer_name: 'Max',
            customer_email: 'max@x.de',
        }));
        expect(showToastMock).toHaveBeenCalledWith('success', expect.any(String));
    });

    it('skips extraction and shows an error toast when the endpoint fails', async () => {
        const onExtracted = vi.fn();
        mockFetchResponse({ error: 'Kein eingebettetes Angebot' }, false);

        const { result } = renderHook(() => useProjectPdfDrop(onExtracted));
        const file = new File(['pdf'], 'offer.pdf', { type: 'application/pdf' });

        await act(async () => {
            await result.current.handleDrop(dropEvent(file));
        });

        expect(onExtracted).not.toHaveBeenCalled();
        expect(showToastMock).toHaveBeenCalledWith('error', 'Kein eingebettetes Angebot');
    });

    it('rejects non-pdf files without fetching and shows an error toast', async () => {
        const onExtracted = vi.fn();
        const mockFetch = vi.fn();
        vi.stubGlobal('fetch', mockFetch);

        const { result } = renderHook(() => useProjectPdfDrop(onExtracted));
        const file = new File(['txt'], 'note.txt', { type: 'text/plain' });

        await act(async () => {
            await result.current.handleDrop(dropEvent(file));
        });

        expect(onExtracted).not.toHaveBeenCalled();
        expect(mockFetch).not.toHaveBeenCalled();
        expect(showToastMock).toHaveBeenCalledWith('error', expect.stringContaining('PDF'));
    });
});