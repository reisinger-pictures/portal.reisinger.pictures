import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook} from '@testing-library/react';
import {useSettings} from '../useSettings';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../usePermissions', () => ({
    usePermissions: vi.fn(),
}));

import useSWR from 'swr';
import {usePermissions} from '../usePermissions';

describe('useSettings.updateWatermark', () => {
    const mutate = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            isLoading: false,
            error: undefined,
            mutate,
        } as never);
        vi.mocked(usePermissions).mockReturnValue({isAdmin: true} as never);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('revalidates the watermark settings after a successful upload', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ok: true}));

        const {result} = renderHook(() => useSettings());
        await result.current.updateWatermark(new FormData());

        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('throws and does not revalidate when the server responds with an error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ok: false, status: 500}));

        const {result} = renderHook(() => useSettings());
        await expect(result.current.updateWatermark(new FormData())).rejects.toThrow('Fehler beim Speichern');

        expect(mutate).not.toHaveBeenCalled();
    });
});
