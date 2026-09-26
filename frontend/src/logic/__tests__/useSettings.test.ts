import {describe, it, expect, vi, beforeEach, afterEach} from 'vitest';
import {renderHook} from '@testing-library/react';
import {useSettings} from '../useSettings';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiUpload: vi.fn(),
}));

vi.mock('../usePermissions', () => ({
    usePermissions: vi.fn(),
}));

import useSWR from 'swr';
import {usePermissions} from '../usePermissions';
import {apiUpload} from '../../api';

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
        vi.mocked(apiUpload).mockResolvedValue({success: true});
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('revalidates the watermark settings after a successful upload', async () => {
        const formData = new FormData();
        const {result} = renderHook(() => useSettings());
        await result.current.updateWatermark(formData);

        expect(apiUpload).toHaveBeenCalledWith('/api/management/settings/watermark', formData);
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('throws and does not revalidate when the server responds with an error', async () => {
        vi.mocked(apiUpload).mockRejectedValueOnce(new Error('Serverfehler'));

        const {result} = renderHook(() => useSettings());
        await expect(result.current.updateWatermark(new FormData())).rejects.toThrow('Fehler beim Speichern');

        expect(mutate).not.toHaveBeenCalled();
    });
});
