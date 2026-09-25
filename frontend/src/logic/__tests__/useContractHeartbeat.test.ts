import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useContractHeartbeat } from '../useContractHeartbeat';
import { fetchSignContract, type SignContractResponse } from '../useContractJoin';

vi.mock('../useContractJoin', () => ({
    fetchSignContract: vi.fn(),
}));

const signResponse = (contentVersion: number) => ({
    contract: { content_version: contentVersion, terms_html: '', items: [], discounts: [], total: 0, billing_details: null, available_roles: [], id: '' },
    signer: { id: '', name: '', email: '', roles: [], status: '' },
});

const tick = async (ms = 5100) => {
    await act(async () => {
        vi.advanceTimersByTime(ms);
    });
};

describe('useContractHeartbeat', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // resetAllMocks (nicht clearAllMocks): nur so startet jeder Test mit
        // leerer Mock-Implementierung. Sonst lecken once-Queues und
        // mockImplementation aus dem Vortest in den Folgetest.
        vi.resetAllMocks();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('calls onStale when content_version changes during polling', async () => {
        const onStale = vi.fn();

        vi.mocked(fetchSignContract).mockResolvedValue({
            contract: { content_version: 1, terms_html: '', items: [], discounts: [], total: 0, billing_details: null, available_roles: [], id: '' },
            signer: { id: '', name: '', email: '', roles: [], status: '' },
        });

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        expect(fetchSignContract).not.toHaveBeenCalled();

        await act(async () => {
            vi.advanceTimersByTime(5100);
        });

        expect(fetchSignContract).toHaveBeenCalledTimes(1);
        expect(onStale).toHaveBeenCalledTimes(1);
    });

    it('does not call onStale when content_version is unchanged', async () => {
        const onStale = vi.fn();

        vi.mocked(fetchSignContract).mockResolvedValue({
            contract: { content_version: 0, terms_html: '', items: [], discounts: [], total: 0, billing_details: null, available_roles: [], id: '' },
            signer: { id: '', name: '', email: '', roles: [], status: '' },
        });

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await act(async () => { vi.advanceTimersByTime(5100); });
        expect(fetchSignContract).toHaveBeenCalledTimes(1);

        await act(async () => { vi.advanceTimersByTime(5100); });
        expect(fetchSignContract).toHaveBeenCalledTimes(2);
        expect(onStale).not.toHaveBeenCalled();
    });

    it('does not poll when signed', () => {
        const onStale = vi.fn();

        renderHook(() => useContractHeartbeat('token-1', 0, true, onStale));

        vi.advanceTimersByTime(5100);

        expect(fetchSignContract).not.toHaveBeenCalled();
        expect(onStale).not.toHaveBeenCalled();
    });

    it('does not poll without token', () => {
        const onStale = vi.fn();

        renderHook(() => useContractHeartbeat(undefined, 0, false, onStale));

        vi.advanceTimersByTime(5100);

        expect(fetchSignContract).not.toHaveBeenCalled();
        expect(onStale).not.toHaveBeenCalled();
    });

    it('uses custom interval', () => {
        const onStale = vi.fn();

        vi.mocked(fetchSignContract).mockResolvedValue({
            contract: { content_version: 0, terms_html: '', items: [], discounts: [], total: 0, billing_details: null, available_roles: [], id: '' },
            signer: { id: '', name: '', email: '', roles: [], status: '' },
        });

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale, 8000));

        vi.advanceTimersByTime(8000);

        expect(fetchSignContract).toHaveBeenCalledTimes(1);
    });

    // --- Error/Abort-Policy-Regressionen (P1-F10) ---

    it('records a rejected heartbeat tick and never adds a retry timer', async () => {
        const onStale = vi.fn();
        vi.mocked(fetchSignContract).mockRejectedValue(new Error('HTTP Fehler 500'));

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();

        expect(fetchSignContract).toHaveBeenCalledTimes(1);
        expect(console.warn).toHaveBeenCalledTimes(1);
        expect(console.warn).toHaveBeenCalledWith('Contract heartbeat request failed:', 'HTTP Fehler 500');
        expect(onStale).not.toHaveBeenCalled();
        // Der einzige Timer bleibt das eine Intervall: ein Fehlschlag startet
        // keinen eigenen Retry und kann so keine Endlosschleife erzeugen.
        expect(vi.getTimerCount()).toBe(1);

        await tick();

        expect(fetchSignContract).toHaveBeenCalledTimes(2);
        expect(vi.getTimerCount()).toBe(1);
    });

    it('records an unchanged failure only once per outage and re-arms after a success', async () => {
        const onStale = vi.fn();
        vi.mocked(fetchSignContract)
            .mockRejectedValueOnce(new Error('Netzwerkfehler'))
            .mockRejectedValueOnce(new Error('Netzwerkfehler'))
            .mockResolvedValueOnce(signResponse(0))
            .mockRejectedValueOnce(new Error('Netzwerkfehler'));

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();
        expect(console.warn).toHaveBeenCalledTimes(1);

        await tick();
        expect(console.warn).toHaveBeenCalledTimes(1);

        await tick();
        expect(onStale).not.toHaveBeenCalled();
        expect(console.warn).toHaveBeenCalledTimes(1);

        await tick();
        expect(console.warn).toHaveBeenCalledTimes(2);
    });

    it('does not record an AbortError and keeps polling afterwards', async () => {
        const onStale = vi.fn();
        vi.mocked(fetchSignContract)
            .mockRejectedValueOnce(new DOMException('The operation was aborted.', 'AbortError'))
            .mockResolvedValueOnce(signResponse(1));

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();

        expect(console.warn).not.toHaveBeenCalled();
        expect(onStale).not.toHaveBeenCalled();

        await tick();

        expect(console.warn).not.toHaveBeenCalled();
        expect(onStale).toHaveBeenCalledTimes(1);
    });

    it('drops an in-flight rejection that settles after unmount', async () => {
        const onStale = vi.fn();
        let pending: { reject: (reason: unknown) => void } | null = null;
        vi.mocked(fetchSignContract).mockImplementation(
            () => new Promise<SignContractResponse>((_resolve, reject) => {
                pending = { reject };
            }),
        );

        const { unmount } = renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();
        expect(fetchSignContract).toHaveBeenCalledTimes(1);

        unmount();

        await act(async () => {
            pending?.reject(new Error('Netzwerkfehler'));
        });

        expect(console.warn).not.toHaveBeenCalled();
        expect(onStale).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('drops an in-flight stale result that resolves after unmount', async () => {
        const onStale = vi.fn();
        let pending: { resolve: (value: SignContractResponse) => void } | null = null;
        vi.mocked(fetchSignContract).mockImplementation(
            () => new Promise<SignContractResponse>(resolve => {
                pending = { resolve };
            }),
        );

        const { unmount } = renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();
        unmount();

        await act(async () => {
            pending?.resolve(signResponse(1));
        });

        expect(onStale).not.toHaveBeenCalled();
    });

    it('stays silent while polling succeeds', async () => {
        const onStale = vi.fn();
        vi.mocked(fetchSignContract).mockResolvedValue(signResponse(0));

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale));

        await tick();
        await tick();

        expect(fetchSignContract).toHaveBeenCalledTimes(2);
        expect(onStale).not.toHaveBeenCalled();
        expect(console.warn).not.toHaveBeenCalled();
    });
});
