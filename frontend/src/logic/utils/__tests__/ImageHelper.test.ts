import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiDownload } from '../../../api';
import { getCompressedBase64 } from '../ImageHelper';

vi.mock('../../../api', () => ({
    apiDownload: vi.fn(),
}));

describe('getCompressedBase64', () => {
    afterEach(() => {
        vi.clearAllMocks();
    });

    it('forwards the caller signal to the authenticated image download', async () => {
        const signal = new AbortController().signal;
        const abortError = new DOMException('The operation was aborted.', 'AbortError');
        vi.mocked(apiDownload).mockRejectedValueOnce(abortError);

        await expect(getCompressedBase64('/media/photo.jpg', 2048, signal)).rejects.toBe(abortError);

        expect(apiDownload).toHaveBeenCalledWith('/media/photo.jpg', { signal });
    });
});
