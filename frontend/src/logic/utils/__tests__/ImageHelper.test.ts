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

    it('forwards the caller signal and an image accept header to the download', async () => {
        const signal = new AbortController().signal;
        const abortError = new DOMException('The operation was aborted.', 'AbortError');
        vi.mocked(apiDownload).mockRejectedValueOnce(abortError);

        await expect(getCompressedBase64('/media/photo.jpg', 2048, signal)).rejects.toBe(abortError);

        expect(apiDownload).toHaveBeenCalledWith('/media/photo.jpg', {
            signal,
            accept: 'image/jpeg, image/png, image/*',
        });
    });

    // FE-5 regression: without a signal the image accept header is still sent.
    it('requests the image representation even without an abort signal', async () => {
        vi.mocked(apiDownload).mockRejectedValueOnce(new Error('stop'));

        await expect(getCompressedBase64('/media/photo.jpg')).rejects.toThrow('stop');

        expect(apiDownload).toHaveBeenCalledWith('/media/photo.jpg', {
            accept: 'image/jpeg, image/png, image/*',
        });
    });
});
