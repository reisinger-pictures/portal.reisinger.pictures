import { describe, it, expect, vi, afterEach } from 'vitest';

vi.mock('../../api', () => ({
    apiDownload: vi.fn(),
    apiMutate: vi.fn(),
    filenameFromContentDisposition: vi.fn((header: string | null) => {
        const match = header?.match(/filename="?([^";]+)"?/i);
        return match ? match[1].trim() : null;
    }),
}));

import { apiDownload, apiMutate } from '../../api';
import {
    buildModelContactSheetBulkUrl,
    buildModelContactSheetShareCreateUrl,
    buildModelContactSheetShareUrl,
    buildModelContactSheetUrl,
    createModelContactSheetShare,
    downloadModelContactSheet,
    downloadModelContactSheetBulk,
    downloadModelContactSheetShare,
    fallbackContactSheetBulkFilename,
    fallbackContactSheetFilename,
    fallbackContactSheetShareFilename,
    MODEL_CONTACT_SHEET_MAX_BULK,
    saveBlobAsFile,
    shareLinkExpiry,
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

    it('exposes the bulk limit of 25 profiles', () => {
        expect(MODEL_CONTACT_SHEET_MAX_BULK).toBe(25);
    });

    it('builds the bulk contact-sheet URL without ids in the path', () => {
        expect(buildModelContactSheetBulkUrl()).toBe('/api/management/models/contact-sheet');
    });

    it('posts the selection and uses the server filename', async () => {
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });
        vi.mocked(apiDownload).mockResolvedValue({ blob, filename: 'models-contact-sheet-external-20260919.pdf' });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheetBulk(['a', 'b'], 'external');

        expect(apiDownload).toHaveBeenCalledWith('/api/management/models/contact-sheet', {
            method: 'POST',
            body: JSON.stringify({ ids: ['a', 'b'], variant: 'external' }),
            headers: { 'Content-Type': 'application/json' },
        });

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe('models-contact-sheet-external-20260919.pdf');
    });

    it('falls back to a deterministic bulk filename when Content-Disposition is missing', async () => {
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });
        vi.mocked(apiDownload).mockResolvedValue({ blob, filename: null });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheetBulk(['a'], 'internal');

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe(fallbackContactSheetBulkFilename('internal'));
        expect(anchor.download).toBe('models-contact-sheet-internal-contact-sheet.pdf');
    });

    it('propagates bulk API errors instead of failing silently', async () => {
        vi.mocked(apiDownload).mockRejectedValue(new Error('HTTP Fehler 422'));
        const { createObjectURL } = stubObjectUrl();

        await expect(downloadModelContactSheetBulk(['a'], 'external')).rejects.toThrow('HTTP Fehler 422');
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    // --- Phase 4: signierter Extern-Link -------------------------------------

    function stubFetch(response: { ok?: boolean; status?: number; contentDisposition?: string | null }) {
        const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' });
        const fetchMock = vi.fn().mockResolvedValue({
            ok: response.ok ?? true,
            status: response.status ?? 200,
            headers: {
                get: (name: string) => (
                    name.toLowerCase() === 'content-disposition'
                        ? response.contentDisposition ?? null
                        : null
                ),
            },
            blob: async () => blob,
        });
        vi.stubGlobal('fetch', fetchMock);
        return { fetchMock, blob };
    }

    it('builds the public share URL with the encoded token', () => {
        expect(buildModelContactSheetShareUrl('tok en/1'))
            .toBe('/api/public/model-contact-sheet/tok%20en%2F1');
        expect(buildModelContactSheetShareUrl('abc')).toBe('/api/public/model-contact-sheet/abc');
    });

    it('creates the share link with the selected ids and no variant', async () => {
        vi.mocked(apiMutate).mockResolvedValue({
            url: 'http://localhost:4321/kontaktbogen/abc',
            expires_at: '2026-10-28T10:00:00+00:00',
            variant: 'external',
            count: 2,
        });

        const share = await createModelContactSheetShare(['a', 'b']);

        expect(buildModelContactSheetShareCreateUrl()).toBe('/api/management/models/contact-sheet/share');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/models/contact-sheet/share', 'POST', {
            ids: ['a', 'b'],
        });
        expect(share.variant).toBe('external');
        expect(share.count).toBe(2);
    });

    it('downloads the shared sheet with a plain fetch and without credentials', async () => {
        const { fetchMock } = stubFetch({
            contentDisposition: 'attachment; filename="models-contact-sheet-external-20260919.pdf"',
        });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheetShare('abc');

        expect(fetchMock).toHaveBeenCalledWith('/api/public/model-contact-sheet/abc', { credentials: 'omit' });
        // Der Endpunkt ist öffentlich: keine Credentials, kein Refresh-Pfad.
        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit | undefined];
        expect(init?.credentials).toBe('omit');

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe('models-contact-sheet-external-20260919.pdf');
    });

    it('falls back to a deterministic share filename when Content-Disposition is missing', async () => {
        stubFetch({ contentDisposition: null });
        stubObjectUrl();
        const click = stubAnchorClick();

        await downloadModelContactSheetShare('abc');

        const anchor = click.mock.instances[0] as unknown as HTMLAnchorElement;
        expect(anchor.download).toBe(fallbackContactSheetShareFilename());
        expect(anchor.download).toBe('models-contact-sheet-external-contact-sheet.pdf');
    });

    it('propagates a failing public download instead of failing silently', async () => {
        stubFetch({ ok: false, status: 404 });
        const { createObjectURL } = stubObjectUrl();

        await expect(downloadModelContactSheetShare('abc')).rejects.toThrow('HTTP Fehler 404');
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    it('reads the expiry from the token claims and tolerates a broken token', () => {
        const payload = btoa(JSON.stringify({ exp: 1793210400 })).replace(/=+$/, '');
        const token = `header.${payload.replace(/\+/g, '-').replace(/\//g, '_')}.signature`;

        const expiry = shareLinkExpiry(token);
        expect(expiry).not.toBeNull();
        expect(expiry?.getTime()).toBe(1793210400 * 1000);

        expect(shareLinkExpiry('not-a-token')).toBeNull();
        expect(shareLinkExpiry('a.b.c')).toBeNull();
    });
});
