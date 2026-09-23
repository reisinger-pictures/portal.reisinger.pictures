import {afterEach, describe, expect, it, vi} from 'vitest';
import {apiMutate, setGlobalErrorCallback} from '../../api';

afterEach(() => {
    setGlobalErrorCallback(null);
    vi.unstubAllGlobals();
});

describe('apiMutate', () => {
    it('preserves custom headers across the 401 refresh retry', async () => {
        const customHeaders = {
            'Idempotency-Key': 'checkout-key-1234567890',
            'Authorization': 'Bearer test-token',
            'X-Test-Header': 'test-value'
        };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(null, {status: 200}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiMutate<{success: boolean}>('/api/orders/checkout', 'POST', {item: 'photo-1'}, {
            headers: customHeaders
        })).resolves.toEqual({success: true});

        expect(fetchMock).toHaveBeenCalledTimes(3);
        const initialOptions = fetchMock.mock.calls[0][1] as RequestInit;
        const retryOptions = fetchMock.mock.calls[2][1] as RequestInit;

        expect(initialOptions.headers).toEqual(expect.objectContaining({
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...customHeaders
        }));
        expect(retryOptions.headers).toEqual(expect.objectContaining(customHeaders));
        expect(retryOptions.headers).toEqual(initialOptions.headers);
    });
});

describe('setGlobalErrorCallback', () => {
    it('stops invoking the previous callback after it is cleared with null', async () => {
        const callback = vi.fn();
        setGlobalErrorCallback(callback);
        setGlobalErrorCallback(null);

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        await expect(apiMutate('/api/anything', 'POST')).rejects.toThrow('Netzwerkfehler');
        expect(callback).not.toHaveBeenCalled();
    });

    it('invokes the registered callback on a network error', async () => {
        const callback = vi.fn();
        setGlobalErrorCallback(callback);

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        await expect(apiMutate('/api/anything', 'POST')).rejects.toThrow('Netzwerkfehler');
        expect(callback).toHaveBeenCalledWith(0, expect.stringContaining('Netzwerkfehler'));
    });
});
