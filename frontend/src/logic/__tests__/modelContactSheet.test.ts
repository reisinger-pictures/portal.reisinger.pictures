import { describe, it, expect, vi, afterEach } from 'vitest';

vi.mock('../../api', () => ({
    apiDownload: vi.fn(),
}));

import { apiDownload } from '../../api';
import {
    buildModelContactSheetUrl,
    downloadModelContactSheet,
    fallbackContactSheetFilename,
    saveBlobAsFile,
} from '../modelContactSheet';

function stubObjectUrl(): { createObjectURL: ReturnType<typeof vi.fn>; revokeObjectURL: ReturnType<typeof vi.fn> } {
    const createObjectURL = vi.fn(() => 'blob:contact-sheet');
    const revokeObjectURL = vi.fn();
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });
    return { createObjectURL, revokeObjectURL };
}

function stubAnchorClick(): ReturnType<typeof vi.spyOn> {
    return vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
}

describe('modelContactSheet', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.clearAllMocks();
    });

    it('builds the contact-sheet URL with the encoded id and the variant', () => {
        expect(buildModelContactSheetUrl('model id/1', 'internal'))
            .toBe('/api/management/models/model%20id%2F1/contact-sheet?variant=internal');
        expect(buildModelContactSheetUrl('abc', 'external'))
            .toBe('/api/management/models/abc/contact-sheet?variant=external');
    });

    it('saves a blob via a synthetic anchor and revokes the object URL', () => {
        const { createObjectURL, revokeObjectURL } = stubObjectUrl();
        const click = stubAnchorClick();
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });

        saveBlobAsFile(blob, 'model-1-internal-20260919.pdf');

        expect(createObjectURL).toHaveBeenCalledWith(blob);
        expect(click).toHaveBeenCalledTimes(1);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:contact-sheet');

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe('model-1-internal-20260919.pdf');
        expect(anchor.href).toContain('blob:contact-sheet');
        // The temporary anchor is detached again.
        expect(document.querySelector('a[download]')).toBeNull();
    });

    it('downloads through the API layer and uses the server filename', async () => {
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });
        vi.mocked(apiDownload).mockResolvedValue({ blob, filename: 'model-7-internal-20260919.pdf' });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheet('7', 'internal');

        expect(apiDownload).toHaveBeenCalledWith('/api/management/models/7/contact-sheet?variant=internal');
        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe('model-7-internal-20260919.pdf');
    });

    it('falls back to a deterministic filename when Content-Disposition is missing', async () => {
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });
        vi.mocked(apiDownload).mockResolvedValue({ blob, filename: null });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheet('7', 'external');

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe(fallbackContactSheetFilename('7', 'external'));
        expect(anchor.download).toBe('model-7-external-contact-sheet.pdf');
    });

    it('propagates API errors instead of failing silently', async () => {
        vi.mocked(apiDownload).mockRejectedValue(new Error('HTTP Fehler 422'));
        const { createObjectURL } = stubObjectUrl();

        await expect(downloadModelContactSheet('7', 'internal')).rejects.toThrow('HTTP Fehler 422');
        expect(createObjectURL).not.toHaveBeenCalled();
    });
});
