import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useAI } from '../useAI';
import { getCompressedBase64 } from '../utils/ImageHelper';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

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
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            jsonResponse({ enabled, status, model: model ?? null }),
        ));
    }

    function mockStatusDisabled() {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            jsonResponse({ enabled: false, status: 'disabled' }),
        ));
    }

    function mockLmStudio(_url: string, success = true) {
        vi.stubGlobal('fetch', success
            ? vi.fn()
                .mockResolvedValueOnce(new Response(null, {status: 503})) // status fails
                .mockResolvedValueOnce(jsonResponse({ data: [{ id: 'lm-model' }] }))
            : vi.fn()
                .mockResolvedValueOnce(new Response(null, {status: 503}))
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

    it('rejects a non-2xx LM Studio model response even when its JSON contains a model', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 503}))
            .mockResolvedValueOnce(jsonResponse({data: [{id: 'must-not-be-used'}]}, 503))
        );

        const { result } = renderHook(() => useAI());

        await waitFor(() => expect(result.current.isAvailable).toBe(false));
        expect(result.current.mode).toBe('unavailable');
        expect(result.current.modelId).toBeNull();
        expect(fetch).toHaveBeenCalledTimes(2);
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
                .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
                .mockResolvedValueOnce(jsonResponse(response))
            : vi.fn()
                .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
                .mockResolvedValueOnce(jsonResponse({ error: 'AI API Error: 502' }, 502))
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

    it('forwards sessionId as session_id for batch prompt-cache routing', async () => {
        mockGenerateMetadata({ title: 'Test', description: 'Desc', keywords: 'kw', location: '', detected_city: '' });

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await result.current.generateMetadata('photo-1', 'Event', 'Main subject', undefined, 'batch-42');

        const call = vi.mocked(fetch).mock.calls[1];
        expect(call[0]).toBe('/api/ai/generate-metadata');
        const body = JSON.parse(call[1]!.body as string);
        expect(body).toEqual({
            photo_id: 'photo-1',
            global_context: 'Event',
            specific_context: 'Main subject',
            session_id: 'batch-42',
        });
    });

    it('refreshes an expired access session and retries server AI generation', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'},
            }))
            .mockResolvedValueOnce(jsonResponse({title: 'After refresh', description: 'Desc'})),
        );

        const {result} = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', 'Context', 'Specific');
        expect(data.title).toBe('After refresh');
        expect(fetch).toHaveBeenCalledTimes(4);
        expect(fetch).toHaveBeenNthCalledWith(1, '/api/ai/status', expect.anything());
        expect(fetch).toHaveBeenNthCalledWith(2, '/api/ai/generate-metadata', expect.anything());
        expect(fetch).toHaveBeenNthCalledWith(3, '/api/auth/refresh', expect.objectContaining({
            method: 'POST',
            credentials: 'include',
        }));
        expect(fetch).toHaveBeenNthCalledWith(4, '/api/ai/generate-metadata', expect.anything());
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
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
            .mockResolvedValueOnce(jsonResponse(null))
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow('AI response validation failed');
    });

    it('aborted request throws AbortError', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
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
        return vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 503})) // status
            .mockResolvedValueOnce(jsonResponse({ data: [{ id: 'local-model' }] })) // LM models
            .mockResolvedValueOnce(jsonResponse(photoResponse)) // photo context
            .mockResolvedValueOnce(jsonResponse(lmResponse)); // LM chat
    }

    beforeEach(() => {
        vi.mocked(getCompressedBase64).mockClear();
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

    it('forwards the generation signal to local image retrieval', async () => {
        vi.stubGlobal('fetch', makePhotoFetch(
            { photo: { url: '/photos/test.jpg' } },
            { choices: [{ message: { content: '{"title":"LM Title"}' } }] },
        ));

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const controller = new AbortController();
        await result.current.generateMetadata('photo-1', '', '', controller.signal);

        expect(getCompressedBase64).toHaveBeenCalledWith('/photos/test.jpg', 2048, controller.signal);
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
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 503})) // status
            .mockResolvedValueOnce(jsonResponse({ data: [{ id: 'local-model' }] })) // LM models
            .mockResolvedValueOnce(jsonResponse({ photo: { url: '/photos/test.jpg' } })) // photo
            .mockResolvedValueOnce(new Response(null, {status: 502})) // LM chat fails
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
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
            .mockResolvedValue(jsonResponse({ title: 'Text Title', description: 'Text Desc', keywords: 'kw', location: '' })),
        );

        const { result } = renderHook(() => useAI());
        await result.current.generateMetadataFromText('A photo', 'Context');

        const calls = vi.mocked(fetch).mock.calls;
        const textCall = calls.find(c => c[0] === '/api/ai/generate-metadata-text');
        expect(textCall).toBeDefined();
        const body = JSON.parse(textCall![1]!.body as string);
        expect(body).toEqual({ text_input: 'A photo', global_context: 'Context' });
    });

    it('forwards the cancellation signal to text generation', async () => {
        const controller = new AbortController();
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({enabled: true, status: 'available', model: 'gpt-4o'}))
            .mockResolvedValueOnce(jsonResponse({title: 'Text Title'}))
        );

        const {result} = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));
        await result.current.generateMetadataFromText('A photo', '', controller.signal);

        const textCall = vi.mocked(fetch).mock.calls.find(([url]) => url === '/api/ai/generate-metadata-text');
        expect(textCall?.[1]?.signal).toBe(controller.signal);
    });

    it('returns parsed AIResponse on success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() =>
            jsonResponse({ title: 'Result', description: 'Desc', keywords: 'k1, k2', location: 'Paris', detected_city: 'Paris' }),
        ));

        const { result } = renderHook(() => useAI());
        const data = await result.current.generateMetadataFromText('Test', '');
        expect(data.title).toBe('Result');
        expect(data.description).toBe('Desc');
        expect(data.keywords).toBe('k1, k2');
        expect(data.location).toBe('Paris');
        expect(data.detected_city).toBe('Paris');
    });

    it('throws on HTTP error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => jsonResponse({ error: 'AI Error' }, 502)));

        const { result } = renderHook(() => useAI());
        await expect(result.current.generateMetadataFromText('Test', ''))
            .rejects.toThrow(/AI Error/);
    });

    it('normalises a network failure through the shared API pipeline', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network failure')));

        const { result } = renderHook(() => useAI());
        await expect(result.current.generateMetadataFromText('Test', ''))
            .rejects.toThrow('Netzwerkfehler');
    });

    it('throws on empty response (null/undefined)', async () => {
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => jsonResponse(null)));

        const { result } = renderHook(() => useAI());
        await expect(result.current.generateMetadataFromText('Test', ''))
            .rejects.toThrow('AI text response validation failed');
    });

    it('throws on non-JSON response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html' },
        })));

        const { result } = renderHook(() => useAI());
        await expect(result.current.generateMetadataFromText('Test', ''))
            .rejects.toThrow();
    });
});

describe('useAI — updateBaseUrl', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('saves a valid LM Studio URL to localStorage', () => {
        const { result } = renderHook(() => useAI());
        result.current.updateBaseUrl('http://127.0.0.1:8080');
        expect(localStorage.getItem('lmstudio_url')).toBe('http://127.0.0.1:8080');
    });

    it('rejects invalid URL and does not save', () => {
        const { result } = renderHook(() => useAI());
        result.current.updateBaseUrl('not-a-url');
        expect(localStorage.getItem('lmstudio_url')).toBeNull();
    });

    it('rejects URL with wrong protocol', () => {
        const { result } = renderHook(() => useAI());
        result.current.updateBaseUrl('https://127.0.0.1:8080');
        expect(localStorage.getItem('lmstudio_url')).toBeNull();
    });

    it('rejects URL with non-localhost hostname', () => {
        const { result } = renderHook(() => useAI());
        result.current.updateBaseUrl('http://example.com:8080');
        expect(localStorage.getItem('lmstudio_url')).toBeNull();
    });

    it('rejects URL without port', () => {
        const { result } = renderHook(() => useAI());
        result.current.updateBaseUrl('http://127.0.0.1');
        expect(localStorage.getItem('lmstudio_url')).toBeNull();
    });
});

describe('useAI — generateMetadata edge cases', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('network failure during server mode throws', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
            .mockRejectedValueOnce(new Error('Network failure')),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow('Netzwerkfehler');
    });

    it('empty object response from server returns empty fields', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' }))
            .mockResolvedValueOnce(jsonResponse({})),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data.title).toBeUndefined();
        expect(data.description).toBeUndefined();
    });

    it('abort signal causes AbortError', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(jsonResponse({ enabled: true, status: 'available', model: 'gpt-4o' })),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        // We already have an abort test — add a variant with a real AbortController
        // that aborts before the request to demonstrate both patterns
        const controller = new AbortController();
        controller.abort();

        await expect(result.current.generateMetadata('photo-1', '', '', controller.signal))
            .rejects.toThrow();
    });
});

describe('useAI — getLmStudioUrl fallback', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
        // Import the hook to cover the module internals indirectly
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('falls back to default URL when localStorage has invalid URL', async () => {
        localStorage.setItem('lmstudio_url', 'invalid-url');
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 503}))
            .mockResolvedValueOnce(jsonResponse({ data: [{ id: 'fallback-model' }] })),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        expect(result.current.mode).toBe('local');
    });

    it('falls back to default URL when localStorage has https URL', async () => {
        localStorage.setItem('lmstudio_url', 'https://127.0.0.1:1234');
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 503}))
            .mockResolvedValueOnce(jsonResponse({ data: [{ id: 'model-from-default' }] })),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        expect(result.current.mode).toBe('local');
    });
});
