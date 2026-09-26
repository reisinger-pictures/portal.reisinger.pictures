import { describe, it, expect, vi, afterEach } from 'vitest';
import { apiDownload, filenameFromContentDisposition } from '../../api';

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function pdfResponse(filename: string): Response {
    return new Response(new Blob(['%PDF-1.7'], { type: 'application/pdf' }), {
        status: 200,
        headers: {
            'Content-Type': 'application/pdf',
            'Content-Disposition': `attachment; filename="${filename}"`,
        },
    });
}

describe('filenameFromContentDisposition', () => {
    it('reads a quoted simple filename', () => {
        expect(filenameFromContentDisposition('attachment; filename="model-1-internal-20260919.pdf"'))
            .toBe('model-1-internal-20260919.pdf');
    });

    it('prefers the RFC 5987 extended filename', () => {
        expect(filenameFromContentDisposition("attachment; filename=\"fallback.pdf\"; filename*=UTF-8''model%20%C3%A41.pdf"))
            .toBe('model ä1.pdf');
    });

    it('returns null without a header or filename parameter', () => {
        expect(filenameFromContentDisposition(null)).toBeNull();
        expect(filenameFromContentDisposition('attachment')).toBeNull();
    });
});

describe('apiDownload', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('returns the blob and the server-provided filename', async () => {
        const fetchMock = vi.fn().mockResolvedValue(pdfResponse('model-1-internal-20260919.pdf'));
        vi.stubGlobal('fetch', fetchMock);

        const result = await apiDownload('/api/management/models/1/contact-sheet?variant=internal');

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/management/models/1/contact-sheet?variant=internal',
            expect.objectContaining({ credentials: 'include' }),
        );
        expect(result.blob).toBeInstanceOf(Blob);
        expect(result.blob.type).toBe('application/pdf');
        expect(result.filename).toBe('model-1-internal-20260919.pdf');
    });

    it('forwards an AbortSignal to the download request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(pdfResponse('photo.jpg'));
        const signal = new AbortController().signal;
        vi.stubGlobal('fetch', fetchMock);

        await apiDownload('/api/photos/1/image', { signal });

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/photos/1/image',
            expect.objectContaining({ signal, credentials: 'include' }),
        );
    });

    // FE-5 regression: callers that download images must be able to override
    // the PDF/octet-stream default accept header.
    it('sends the caller-provided accept header for image downloads', async () => {
        const fetchMock = vi.fn().mockResolvedValue(pdfResponse('photo.jpg'));
        vi.stubGlobal('fetch', fetchMock);

        await apiDownload('/api/photos/1/image', { accept: 'image/jpeg, image/png, image/*' });

        expect(fetchMock).toHaveBeenCalledWith(
            '/api/photos/1/image',
            expect.objectContaining({
                headers: expect.objectContaining({ Accept: 'image/jpeg, image/png, image/*' }),
            }),
        );
    });

    it('normalises a 422 response into a thrown ApiError', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(422, { message: 'Ungültige Variante.' })));

        await expect(apiDownload('/api/management/models/1/contact-sheet?variant=bogus'))
            .rejects.toThrow('Ungültige Variante.');
    });

    it('throws a network error when fetch rejects', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        await expect(apiDownload('/api/anything')).rejects.toThrow('Netzwerkfehler');
    });

    it('yields a null filename when the header is absent', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(new Blob(['%PDF']), {
            status: 200,
            headers: { 'Content-Type': 'application/pdf' },
        })));

        const result = await apiDownload('/api/management/models/1/contact-sheet?variant=internal');
        expect(result.filename).toBeNull();
    });
});
