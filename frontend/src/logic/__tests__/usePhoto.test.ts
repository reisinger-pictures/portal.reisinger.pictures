import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { usePhoto } from '../usePhoto';

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
    fetcher: vi.fn(),
}));

import { apiMutate, fetcher } from '../../api';

describe('usePhoto', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('updateMetadata calls apiMutate with correct parameters', async () => {
        const mockResponse = { success: true };
        vi.mocked(apiMutate).mockResolvedValue(mockResponse);

        const { result } = renderHook(() => usePhoto());
        const res = await result.current.updateMetadata('photo-1', { title: 'New Title', description: 'New Desc' });

        expect(apiMutate).toHaveBeenCalledWith('/api/photos/photo-1/meta', 'PUT', { title: 'New Title', description: 'New Desc' });
        expect(res).toEqual(mockResponse);
    });

    it('getVersions calls fetcher with correct URL', async () => {
        const mockVersions = [{ id: 'v1', created_at: '2025-01-01T00:00:00Z', title: 'Version 1' }];
        vi.mocked(fetcher).mockResolvedValue(mockVersions);

        const { result } = renderHook(() => usePhoto());
        const versions = await result.current.getVersions('photo-1');

        expect(fetcher).toHaveBeenCalledWith('/api/photos/photo-1/versions');
        expect(versions).toEqual(mockVersions);
    });

    it('deletePhoto calls apiMutate with DELETE method', async () => {
        vi.mocked(apiMutate).mockResolvedValue(undefined);

        const { result } = renderHook(() => usePhoto());
        await result.current.deletePhoto('photo-1');

        expect(apiMutate).toHaveBeenCalledWith('/api/photos/photo-1', 'DELETE');
    });

    it('revertMetadata calls apiMutate with POST method', async () => {
        vi.mocked(apiMutate).mockResolvedValue(undefined);

        const { result } = renderHook(() => usePhoto());
        await result.current.revertMetadata('photo-1', 'v1');

        expect(apiMutate).toHaveBeenCalledWith('/api/photos/photo-1/revert/v1', 'POST');
    });

    it('updateMetadata handles errors correctly', async () => {
        vi.mocked(apiMutate).mockRejectedValue(new Error('Update failed'));

        const { result } = renderHook(() => usePhoto());
        await expect(result.current.updateMetadata('photo-1', { title: 'Test' }))
            .rejects.toThrow('Update failed');
    });
});
