import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useAI } from '../useAI';

describe('useAI — Mode Resolution', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    function mockStatus(status: string, enabled: boolean, model?: string) {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ enabled, status, model: model ?? null }),
        }));
    }

    function mockStatusDisabled() {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ enabled: false, status: 'disabled' }),
        }));
    }

    function mockLmStudio(_url: string, success = true) {
        vi.stubGlobal('fetch', success
            ? vi.fn()
                .mockResolvedValueOnce({ ok: false }) // status fails
                .mockResolvedValueOnce({
                    ok: true,
                    json: () => Promise.resolve({ data: [{ id: 'lm-model' }] }),
                })
            : vi.fn()
                .mockResolvedValueOnce({ ok: false })
                .mockRejectedValueOnce(new Error('LM Studio unreachable')),
        );
    }

    it('available → server mode when /api/ai/status returns enabled', async () => {
        mockStatus('available', true, 'gpt-4o');
        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        expect(result.current.mode).toBe('server');
        expect(result.current.modelId).toBe('gpt-4o');
    });

    it('disabled → unavailable, NO LM Studio fallback attempted', async () => {
        mockStatusDisabled();

        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(false));

        expect(result.current.mode).toBe('unavailable');
        expect(result.current.modelId).toBeNull();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('unconfigured → LM Studio fallback tried and succeeds → local mode', async () => {
        mockLmStudio('http://127.0.0.1:1234', true);

        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        expect(result.current.mode).toBe('local');
        expect(result.current.modelId).toBe('lm-model');
    });

    it('both fail → unavailable', async () => {
        mockLmStudio('http://127.0.0.1:1234', false);

        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(false));

        expect(result.current.mode).toBe('unavailable');
        expect(result.current.modelId).toBeNull();
    });

    it('custom lmstudio_url from localStorage used as fallback', async () => {
        localStorage.setItem('lmstudio_url', 'http://custom:4321');
        mockLmStudio('http://custom:4321', true);

        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        expect(result.current.mode).toBe('local');
    });
});

describe('useAI — generateMetadata server mode', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    function mockGenerateMetadata(response: unknown, ok = true) {
        vi.stubGlobal('fetch', ok
            ? vi.fn()
                .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
                .mockResolvedValueOnce({
                    ok: true,
                    json: () => Promise.resolve(response),
                })
            : vi.fn()
                .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
                .mockResolvedValueOnce({
                    ok: false,
                    json: () => Promise.resolve({ error: 'AI API Error: 502' }),
                })
        );
    }

    it('calls /api/ai/generate-metadata with correct parameters', async () => {
        mockGenerateMetadata({ title: 'Test', description: 'Desc', keywords: 'kw', location: '', detected_city: '' });

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await result.current.generateMetadata('photo-1', 'Event', 'Main subject');

        const call = vi.mocked(fetch).mock.calls[1];
        expect(call[0]).toBe('/api/ai/generate-metadata');
        const body = JSON.parse(call[1]!.body as string);
        expect(body).toEqual({
            photo_id: 'photo-1',
            global_context: 'Event',
            specific_context: 'Main subject',
        });
    });

    it('returns parsed AIResponse on success', async () => {
        const mockResponse = { title: 'AI Title', description: 'AI Desc', keywords: 'k1, k2', location: 'Vienna', detected_city: 'Vienna' };
        mockGenerateMetadata(mockResponse);

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data).toEqual(mockResponse);
    });

    it('throws on HTTP error with status message', async () => {
        mockGenerateMetadata({}, false);

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow(/502/);
    });

    it('throws on invalid response (Zod validation fails)', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve(null),
            })
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow('AI response validation failed');
    });

    it('aborted request throws AbortError', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const controller = new AbortController();
        controller.abort();

        await expect(result.current.generateMetadata('photo-1', '', '', controller.signal))
            .rejects.toThrow();
    });
});

vi.mock('../utils/ImageHelper', () => ({
    getCompressedBase64: vi.fn(() => Promise.resolve('data:image/jpeg;base64,fake')),
}));

describe('useAI — generateMetadata local mode', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    function makePhotoFetch(photoResponse: unknown, lmResponse: unknown) {
        const blob = new Blob(['fake-image'], { type: 'image/jpeg' });
        return vi.fn()
            .mockResolvedValueOnce({ ok: false }) // status
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ data: [{ id: 'local-model' }] }) }) // LM models
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve(photoResponse), blob: () => Promise.resolve(blob) }) // photo context
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve(lmResponse) }); // LM chat
    }

    beforeEach(() => {
        vi.stubGlobal('URL.createObjectURL', vi.fn(() => 'blob:test'));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('calls LM Studio /v1/chat/completions with image data', async () => {
        vi.stubGlobal('fetch', makePhotoFetch(
            { photo: { url: '/photos/test.jpg' } },
            { choices: [{ message: { content: '{"title":"LM Title","description":"LM Desc","keywords":"kw","location":"","detected_city":""}' } }] },
        ));

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data.title).toBe('LM Title');
    });

    it('returns parsed AIResponse on success', async () => {
        vi.stubGlobal('fetch', makePhotoFetch(
            { photo: { url: '/photos/test.jpg' } },
            { choices: [{ message: { content: '{"title":"Local","description":"Desc","keywords":"kw","location":"Berlin","detected_city":"Berlin"}' } }] },
        ));

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data.title).toBe('Local');
        expect(data.location).toBe('Berlin');
        expect(data.detected_city).toBe('Berlin');
    });

    it('throws on LM Studio HTTP error', async () => {
        const blob = new Blob(['fake-image'], { type: 'image/jpeg' });
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: false }) // status
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ data: [{ id: 'local-model' }] }) }) // LM models
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ photo: { url: '/photos/test.jpg' } }), blob: () => Promise.resolve(blob) }) // photo
            .mockResolvedValueOnce({ ok: false }) // LM chat fails
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow('LM Studio API Error');
    });

    it('handles markdown-wrapped JSON from LM Studio', async () => {
        vi.stubGlobal('fetch', makePhotoFetch(
            { photo: { url: '/photos/test.jpg' } },
            { choices: [{ message: { content: '```json\n{"title":"MD Title","description":"MD Desc","keywords":"md,kw","location":"","detected_city":""}\n```' } }] },
        ));

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data.title).toBe('MD Title');
        expect(data.description).toBe('MD Desc');
    });
});

describe('useAI — generateMetadataFromText', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('calls /api/ai/generate-metadata-text with text_input and global_context', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
            .mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ title: 'Text Title', description: 'Text Desc', keywords: 'kw', location: '' }),
            }),
        );

        const { result } = renderHook(() => useAI());
        await result.current.generateMetadataFromText('A photo', 'Context');

        const calls = vi.mocked(fetch).mock.calls;
        const textCall = calls.find(c => c[0] === '/api/ai/generate-metadata-text');
        expect(textCall).toBeDefined();
        const body = JSON.parse(textCall![1]!.body as string);
        expect(body).toEqual({ text_input: 'A photo', global_context: 'Context' });
    });

    it('returns parsed AIResponse on success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ title: 'Result', description: 'Desc', keywords: 'k1, k2', location: 'Paris', detected_city: 'Paris' }),
        }));

        const { result } = renderHook(() => useAI());
        const data = await result.current.generateMetadataFromText('Test', '');
        expect(data.title).toBe('Result');
        expect(data.description).toBe('Desc');
        expect(data.keywords).toBe('k1, k2');
        expect(data.location).toBe('Paris');
        expect(data.detected_city).toBe('Paris');
    });

    it('throws on HTTP error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            json: () => Promise.resolve({ error: 'AI Error' }),
        }));

        const { result } = renderHook(() => useAI());
        await expect(result.current.generateMetadataFromText('Test', ''))
            .rejects.toThrow(/AI Error/);
    });
});
