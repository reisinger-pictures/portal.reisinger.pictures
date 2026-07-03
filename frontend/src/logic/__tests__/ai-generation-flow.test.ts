import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useAI, aiResponseSchema } from '../useAI';

describe('aiGenerationFlow — server mode full flow', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    function mockAvailable(response: unknown, ok = true) {
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
                }),
        );
    }

    it('completes full flow with realistic photo ID and context', async () => {
        mockAvailable({ title: 'Sunset over Vienna', description: 'A beautiful sunset view over the city of Vienna from Kahlenberg.', keywords: 'sunset, Vienna, cityscape, dusk, skyline', location: 'Kahlenberg, Vienna', detected_city: 'Vienna' });

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-vienna-2024', 'Nature & Landscape', 'Evening shot from Kahlenberg viewpoint');

        expect(data.title).toBe('Sunset over Vienna');
        expect(data.description).toContain('Vienna');
        expect(data.keywords).toContain('sunset');
        expect(data.location).toBe('Kahlenberg, Vienna');
        expect(data.detected_city).toBe('Vienna');
    });

    it('handles HTTP 500 error from server', async () => {
        mockAvailable({}, false);

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow(/502/);
    });

    it('handles empty response from server', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({}),
            }),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        const parsed = aiResponseSchema.safeParse(data);
        expect(parsed.success).toBe(true);
    });

    it('handles non-JSON response as validation error', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.reject(new Error('Unexpected token')),
            }),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow();
    });

    it('handles abort signal during request', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) }),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const controller = new AbortController();
        controller.abort();

        await expect(result.current.generateMetadata('photo-1', '', '', controller.signal))
            .rejects.toThrow();
    });

    it('handles network failure during generateMetadata', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ enabled: true, status: 'available', model: 'gpt-4o' }) })
            .mockRejectedValueOnce(new Error('net::ERR_CONNECTION_REFUSED')),
        );

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await expect(result.current.generateMetadata('photo-1', '', ''))
            .rejects.toThrow('net::ERR_CONNECTION_REFUSED');
    });

    it('handles partial response with missing optional fields', async () => {
        mockAvailable({ title: 'Just a title' });

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        const data = await result.current.generateMetadata('photo-1', '', '');
        expect(data.title).toBe('Just a title');
        expect(data.description).toBeUndefined();
        expect(data.keywords).toBeUndefined();
        expect(data.detected_city).toBeUndefined();
    });

    it('sends correct photo_id and context parameters', async () => {
        mockAvailable({ title: 'Test', description: 'Desc', keywords: '', location: '', detected_city: '' });

        const { result } = renderHook(() => useAI());
        await waitFor(() => expect(result.current.isAvailable).toBe(true));

        await result.current.generateMetadata('photo-xyz-789', 'Event: Wedding', 'Bride and groom at altar');

        const call = vi.mocked(fetch).mock.calls[1];
        const body = JSON.parse(call[1]!.body as string);
        expect(body.photo_id).toBe('photo-xyz-789');
        expect(body.global_context).toBe('Event: Wedding');
        expect(body.specific_context).toBe('Bride and groom at altar');
    });
});

describe('aiGenerationFlow — AIResponse schema validation', () => {
    it('parses keywords as string', () => {
        const result = aiResponseSchema.safeParse({ keywords: 'one, two, three' });
        expect(result.success).toBe(true);
        if (result.success) expect(result.data.keywords).toBe('one, two, three');
    });

    it('parses keywords as array and joins with comma', () => {
        const result = aiResponseSchema.safeParse({ keywords: ['one', 'two', 'three'] });
        expect(result.success).toBe(true);
        if (result.success) expect(result.data.keywords).toBe('one, two, three');
    });

    it('accepts empty object', () => {
        const result = aiResponseSchema.safeParse({});
        expect(result.success).toBe(true);
    });

    it('rejects null values (optional does not accept null)', () => {
        const result = aiResponseSchema.safeParse({ title: null, description: null, keywords: null });
        expect(result.success).toBe(false);
    });
});
