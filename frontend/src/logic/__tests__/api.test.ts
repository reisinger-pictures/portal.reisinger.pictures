import {afterEach, describe, expect, it, vi} from 'vitest';
import {
    apiDownload,
    apiMutate,
    apiUpload,
    fetcher,
    type AuthMeUser,
    refreshAuthSession,
    setGlobalErrorCallback,
} from '../../api';

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

    it('retries a rating mutation after a 401 through the shared refresh pipeline', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiMutate('/api/photos/p1/rate', 'POST', {rating: 5, comment: 'Great!'}))
            .resolves.toEqual({success: true});

        expect(fetchMock.mock.calls.map(([input]) =>
            input instanceof Request ? input.url : input.toString()
        )).toEqual(['/api/photos/p1/rate', '/api/auth/refresh', '/api/photos/p1/rate']);
        expect(fetchMock.mock.calls[2][1]).toEqual(expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({rating: 5, comment: 'Great!'}),
        }));
    });
});

describe('auth session refresh', () => {
    it('refreshes an expired access session for /api/auth/me and retries the original request', async () => {
        const user: AuthMeUser = {
            id: 'user-1',
            guest_id: null,
            name: 'Test User',
            email: 'test@example.com',
            billing_name: 'Test User',
            billing_company: 'Example GmbH',
            billing_street: 'Teststraße 1',
            billing_zip: '1010',
            billing_city: 'Wien',
            can_edit_metadata: true,
            can_purchase_upgrades: true,
            is_org_admin: true,
            is_power_user: false,
            brand: 'rp',
            is_cross_brand: false,
            is_super_admin: false,
            is_admin: false,
            is_photographer: false,
            is_pending: false,
            roles: [],
            transient_galleries: [],
            transient_meta_galleries: [],
            photographer_gallery_groups: [],
        };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify(user), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetcher('/api/auth/me')).resolves.toEqual(user);

        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/auth/me');
        expect(fetchMock.mock.calls[1][0]).toBe('/api/auth/refresh');
        expect(fetchMock.mock.calls[2][0]).toBe('/api/auth/me');

        const refreshOptions = fetchMock.mock.calls[1][1] as RequestInit;
        expect(refreshOptions).toEqual(expect.objectContaining({
            method: 'POST',
            credentials: 'include',
        }));
        expect(refreshOptions.headers).not.toHaveProperty('Authorization');
        expect(refreshOptions).not.toHaveProperty('body');

        const retryOptions = fetchMock.mock.calls[2][1] as RequestInit;
        expect(retryOptions.credentials).toBe('include');
    });

    it('deduplicates concurrent refreshes while retrying each original request once', async () => {
        let resolveRefresh: (response: Response) => void = () => undefined;
        const refreshResponse = new Promise<Response>((resolve) => {
            resolveRefresh = resolve;
        });
        let resolveRefreshStarted: () => void = () => undefined;
        const refreshStarted = new Promise<void>((resolve) => {
            resolveRefreshStarted = resolve;
        });

        const initialRequestCounts = new Map<string, number>();
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            const url = input instanceof Request ? input.url : input.toString();
            if (url === '/api/auth/refresh') {
                resolveRefreshStarted();
                return refreshResponse;
            }

            const requestCount = initialRequestCounts.get(url) ?? 0;
            initialRequestCounts.set(url, requestCount + 1);
            const responseBody = JSON.stringify({url});
            return Promise.resolve(new Response(
                requestCount === 0 ? null : responseBody,
                {
                    status: requestCount === 0 ? 401 : 200,
                    headers: {'Content-Type': 'application/json'},
                }
            ));
        });
        vi.stubGlobal('fetch', fetchMock);

        const firstRequest = fetcher<{url: string}>('/api/orders/a');
        const secondRequest = fetcher<{url: string}>('/api/orders/b');
        await refreshStarted;

        const refreshCallsBeforeRelease = fetchMock.mock.calls.filter(([input]) =>
            (input instanceof Request ? input.url : input.toString()) === '/api/auth/refresh'
        );
        expect(refreshCallsBeforeRelease).toHaveLength(1);

        resolveRefresh(new Response(JSON.stringify({success: true}), {
            status: 200,
            headers: {'Content-Type': 'application/json'},
        }));

        await expect(Promise.all([firstRequest, secondRequest])).resolves.toEqual([
            {url: '/api/orders/a'},
            {url: '/api/orders/b'},
        ]);
        expect(initialRequestCounts.get('/api/orders/a')).toBe(2);
        expect(initialRequestCounts.get('/api/orders/b')).toBe(2);
        expect(fetchMock.mock.calls.filter(([input]) =>
            (input instanceof Request ? input.url : input.toString()) === '/api/auth/refresh'
        )).toHaveLength(1);
    });

    it('does not retry or log out recursively when the refresh credential is invalid', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({error: 'Token konnte nicht aktualisiert werden.'}), {
                status: 401,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetcher('/api/auth/me')).rejects.toMatchObject({status: 401});

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.map(([input]) =>
            input instanceof Request ? input.url : input.toString()
        )).toEqual(['/api/auth/me', '/api/auth/refresh']);
    });

    it('does not start a refresh for a transient /api/auth/me failure', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(
            JSON.stringify({error: 'Auth-Dienst vorübergehend nicht verfügbar.'}),
            {status: 503, headers: {'Content-Type': 'application/json'}}
        ));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetcher('/api/auth/me')).rejects.toMatchObject({
            status: 503,
            message: 'Auth-Dienst vorübergehend nicht verfügbar.'
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/auth/me');
    });

    it('keeps the original 401 when the shared refresh endpoint is transiently unavailable', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({error: 'Refresh vorübergehend nicht verfügbar.'}), {
                status: 503,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetcher('/api/auth/me')).rejects.toMatchObject({status: 401});

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.map(([input]) =>
            input instanceof Request ? input.url : input.toString()
        )).toEqual(['/api/auth/me', '/api/auth/refresh']);
    });

    it('preserves the status when a failed auth response contains non-object JSON', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(null), {
                status: 401,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({error: 'Token konnte nicht aktualisiert werden.'}), {
                status: 401,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(fetcher('/api/auth/me')).rejects.toMatchObject({
            status: 401,
            message: 'HTTP Fehler 401',
            info: {body: null},
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('refreshes and retries a multipart upload with the same FormData body', async () => {
        const formData = new FormData();
        formData.append('gallery_id', 'gallery-1');
        formData.append('file', new Blob(['image'], {type: 'image/jpeg'}), 'photo.jpg');
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({photo_id: 'photo-1'}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        await expect(apiUpload<{photo_id: string}>('/api/management/upload', formData))
            .resolves.toEqual({photo_id: 'photo-1'});

        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls.map(([input]) =>
            input instanceof Request ? input.url : input.toString()
        )).toEqual(['/api/management/upload', '/api/auth/refresh', '/api/management/upload']);
        expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({
            method: 'POST',
            credentials: 'include',
            body: formData,
        }));
        expect(fetchMock.mock.calls[2][1]).toEqual(expect.objectContaining({body: formData}));
    });

    it('refreshes and retries a binary POST through apiDownload', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(new Blob(['%PDF']), {
                status: 200,
                headers: {'Content-Type': 'application/pdf'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await apiDownload('/api/management/invoices/manual', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({invoice_number: 'R-1'}),
        });

        expect(result.blob.type).toBe('application/pdf');
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({
            method: 'POST',
            credentials: 'include',
            body: JSON.stringify({invoice_number: 'R-1'}),
        }));
        expect(fetchMock.mock.calls[2][1]).toEqual(expect.objectContaining({method: 'POST'}));
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

describe('AbortError preservation', () => {
    it('rethrows the original cancellation error from every shared API helper', async () => {
        const abortError = new DOMException('The operation was aborted.', 'AbortError');
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(abortError));

        await expect(fetcher('/api/gallery')).rejects.toBe(abortError);
        await expect(apiMutate('/api/gallery/opt-in', 'POST', {wants_notifications: true})).rejects.toBe(abortError);
        await expect(apiDownload('/api/photos/1/image')).rejects.toBe(abortError);
        await expect(apiUpload('/api/management/upload', new FormData())).rejects.toBe(abortError);
    });

    it('does not invoke the global network-error callback for cancellation', async () => {
        const callback = vi.fn();
        const abortError = new DOMException('The operation was aborted.', 'AbortError');
        setGlobalErrorCallback(callback);
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(abortError));

        await expect(apiMutate('/api/anything', 'POST')).rejects.toBe(abortError);
        expect(callback).not.toHaveBeenCalled();
    });

    it('preserves cancellation if the shared refresh request itself aborts', async () => {
        const abortError = new DOMException('The operation was aborted.', 'AbortError');
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(abortError));

        await expect(refreshAuthSession()).rejects.toBe(abortError);
    });

    it('does not start a shared refresh for a caller cancelled before the 401 boundary', async () => {
        const controller = new AbortController();
        const abortError = new DOMException('caller cancelled', 'AbortError');
        const fetchMock = vi.fn().mockResolvedValue(new Response(null, {status: 401}));
        vi.stubGlobal('fetch', fetchMock);

        const request = apiMutate('/api/orders/checkout', 'POST', {}, {signal: controller.signal});
        controller.abort(abortError);

        await expect(request).rejects.toBe(abortError);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/orders/checkout');
    });

    it('does not replay a request when its caller is cancelled while refresh is shared', async () => {
        const controller = new AbortController();
        const abortError = new DOMException('cancelled during refresh', 'AbortError');
        let resolveRefresh: (response: Response) => void = () => undefined;
        let resolveRefreshStarted: () => void = () => undefined;
        const refreshResponse = new Promise<Response>((resolve) => {
            resolveRefresh = resolve;
        });
        const refreshStarted = new Promise<void>((resolve) => {
            resolveRefreshStarted = resolve;
        });
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            const url = input instanceof Request ? input.url : input.toString();
            if (url === '/api/auth/refresh') {
                resolveRefreshStarted();
                return refreshResponse;
            }
            return Promise.resolve(new Response(null, {status: 401}));
        });
        vi.stubGlobal('fetch', fetchMock);

        const request = fetcher('/api/gallery', {signal: controller.signal});
        await refreshStarted;
        controller.abort(abortError);
        resolveRefresh(new Response(JSON.stringify({success: true}), {
            status: 200,
            headers: {'Content-Type': 'application/json'}
        }));

        await expect(request).rejects.toBe(abortError);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/api/auth/refresh');
    });

    it('normalises and reports a transport failure on the post-refresh retry', async () => {
        const callback = vi.fn();
        setGlobalErrorCallback(callback);
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockRejectedValueOnce(new TypeError('offline'));

        vi.stubGlobal('fetch', fetchMock);

        await expect(apiMutate('/api/orders/checkout', 'POST')).rejects.toMatchObject({
            status: 0,
            message: expect.stringContaining('Netzwerkfehler')
        });
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(callback).toHaveBeenCalledWith(0, expect.stringContaining('Netzwerkfehler'));
    });

    it('preserves an AbortError from the post-refresh retry without reporting it', async () => {
        const callback = vi.fn();
        const abortError = new DOMException('retry cancelled', 'AbortError');
        setGlobalErrorCallback(callback);
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockRejectedValueOnce(abortError));

        await expect(apiMutate('/api/orders/checkout', 'POST')).rejects.toBe(abortError);
        expect(callback).not.toHaveBeenCalled();
    });
});
