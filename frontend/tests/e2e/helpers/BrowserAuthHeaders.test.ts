import { describe, expect, it, vi } from 'vitest';
import type { APIRequestContext } from '@playwright/test';
import {
    browserAuthHeaders,
    browserAuthHeadersFromRequest,
    E2E_FRONTEND_ORIGIN,
} from './BrowserAuthHeaders';

function jarRequest(options: {
    cookies: Array<{ name: string; value: string }>;
    mintOk?: boolean;
    mintedCookies?: Array<{ name: string; value: string }>;
}): { request: APIRequestContext; get: ReturnType<typeof vi.fn> } {
    let cookies = options.cookies;
    const get = vi.fn().mockImplementation(async () => {
        if (options.mintOk !== false) cookies = options.mintedCookies ?? cookies;
        return {ok: () => options.mintOk !== false};
    });
    const request = {
        get,
        storageState: vi.fn().mockImplementation(async () => ({cookies, origins: []})),
    } as unknown as APIRequestContext;

    return {request, get};
}

describe('browserAuthHeaders', () => {
    it('reads the token out of a folded Cookie header', () => {
        expect(browserAuthHeaders('rp_jwt=abc; XSRF-TOKEN=signed-token; Path=/')).toEqual({
            'Origin': E2E_FRONTEND_ORIGIN,
            'X-XSRF-TOKEN': 'signed-token',
        });
    });

    it('returns only the origin when the cookie header carries no token', () => {
        expect(browserAuthHeaders('rp_jwt=abc')).toEqual({'Origin': E2E_FRONTEND_ORIGIN});
    });
});

describe('browserAuthHeadersFromRequest', () => {
    it('uses the token the request fixture jar already holds', async () => {
        const {request, get} = jarRequest({cookies: [{name: 'XSRF-TOKEN', value: 'jar-token'}]});

        await expect(browserAuthHeadersFromRequest(request)).resolves.toEqual({
            'Origin': E2E_FRONTEND_ORIGIN,
            'X-XSRF-TOKEN': 'jar-token',
        });
        expect(get).not.toHaveBeenCalled();
    });

    it('mints a token through GET /api/ping when the jar has none', async () => {
        const {request, get} = jarRequest({
            cookies: [{name: 'rp_jwt', value: 'admin-token'}],
            mintedCookies: [
                {name: 'rp_jwt', value: 'admin-token'},
                {name: 'XSRF-TOKEN', value: 'minted-token'},
            ],
        });

        await expect(browserAuthHeadersFromRequest(request)).resolves.toEqual({
            'Origin': E2E_FRONTEND_ORIGIN,
            'X-XSRF-TOKEN': 'minted-token',
        });
        expect(get).toHaveBeenCalledWith('/api/ping', {headers: {'Accept': 'application/json'}});
    });

    it('sends only the origin when the mint fails, so the server fails closed', async () => {
        const {request} = jarRequest({cookies: [], mintOk: false});

        await expect(browserAuthHeadersFromRequest(request)).resolves.toEqual({
            'Origin': E2E_FRONTEND_ORIGIN,
        });
    });
});
