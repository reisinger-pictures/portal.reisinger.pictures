import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useVolumePresets } from '../useVolumePresets';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate, fetcher } from '../../api';

const mockPresets = {
    presets: [
        { id: 'p1', name: 'Standard', is_default: true, tiers: [{ position: 0, min_quantity: 0, price_cents: 3000 }] },
    ],
};

describe('useVolumePresets', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('fetches presets from the management endpoint', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockPresets,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useVolumePresets());
        expect(useSWR).toHaveBeenCalledWith('/api/management/settings/volume-presets', fetcher);
        expect(result.current.presets).toHaveLength(1);
        expect(result.current.presets?.[0].name).toBe('Standard');
    });

    it('shows loading state', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useVolumePresets());
        expect(result.current.isLoading).toBe(true);
    });

    it('create posts the payload and reloads', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const { result } = renderHook(() => useVolumePresets());
        await result.current.createPreset({
            name: 'Werbung',
            tiers: [{ min_quantity: 0, price_cents: 5000 }],
        });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/settings/volume-presets', 'POST', {
            name: 'Werbung',
            tiers: [{ min_quantity: 0, price_cents: 5000 }],
        });
        expect(mutate).toHaveBeenCalled();
    });

    it('update sends the full preset payload', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: mockPresets,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const { result } = renderHook(() => useVolumePresets());
        await result.current.updatePreset('p1', { name: 'Neu', tiers: [{ min_quantity: 0, price_cents: 1000 }] });
        expect(apiMutate).toHaveBeenCalledWith('/api/management/settings/volume-presets/p1', 'PUT', {
            name: 'Neu',
            tiers: [{ min_quantity: 0, price_cents: 1000 }],
        });
        expect(mutate).toHaveBeenCalled();
    });

    it('setDefault posts to the default endpoint', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: mockPresets,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        const { result } = renderHook(() => useVolumePresets());
        await result.current.setDefaultPreset('p1');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/settings/volume-presets/p1/default', 'POST', {});
        expect(mutate).toHaveBeenCalled();
    });
});
