import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useContractHeartbeat } from '../useContractHeartbeat';
import { fetchSignContract } from '../useContractJoin';

vi.mock('../useContractJoin', () => ({
    fetchSignContract: vi.fn(),
}));

describe('useContractHeartbeat', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('calls onStale when content_version changes during polling', async () => {
        const onStale = vi.fn();

        vi.mocked(fetchSignContract).mockResolvedValue({
            contract: { content_version: 1, terms_html: '', items: [], discounts: [], billing_details: null, available_roles: [], id: '' },
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
            contract: { content_version: 0, terms_html: '', items: [], discounts: [], billing_details: null, available_roles: [], id: '' },
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
            contract: { content_version: 0, terms_html: '', items: [], discounts: [], billing_details: null, available_roles: [], id: '' },
            signer: { id: '', name: '', email: '', roles: [], status: '' },
        });

        renderHook(() => useContractHeartbeat('token-1', 0, false, onStale, 8000));

        vi.advanceTimersByTime(8000);

        expect(fetchSignContract).toHaveBeenCalledTimes(1);
    });
});
