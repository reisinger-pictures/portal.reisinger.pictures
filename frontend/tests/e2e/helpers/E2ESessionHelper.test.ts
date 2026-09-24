import { describe, expect, it, vi } from 'vitest';
import type { APIRequestContext } from '@playwright/test';
import { E2ESessionHelper } from './E2ESessionHelper';

type FakeResponse = {
    ok: () => boolean;
    status: () => number;
    text: () => Promise<string>;
    json: () => Promise<unknown>;
    headersArray: () => Array<{ name: string; value: string }>;
};

function response(options: {
    ok: boolean;
    status?: number;
    body?: unknown;
    text?: string;
    cookies?: Array<{ name: string; value: string }>;
}): FakeResponse {
    return {
        ok: () => options.ok,
        status: () => options.status ?? (options.ok ? 200 : 500),
        text: vi.fn().mockResolvedValue(options.text ?? ''),
        json: vi.fn().mockResolvedValue(options.body ?? {}),
        headersArray: () => options.cookies ?? [],
    };
}

function requestWith(post: ReturnType<typeof vi.fn>, get: ReturnType<typeof vi.fn>, put: ReturnType<typeof vi.fn>): APIRequestContext {
    return { post, get, put } as unknown as APIRequestContext;
}

function loginResponse(): FakeResponse {
    return response({
        ok: true,
        cookies: [{ name: 'Set-Cookie', value: 'rp_jwt=admin-token; Path=/; HttpOnly' }],
    });
}

describe('E2ESessionHelper.createIsolatedUser', () => {
    it('fails before role parsing when the role lookup is not successful', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce(loginResponse())
            .mockResolvedValueOnce(response({ ok: true, body: { user: { id: 'user-1' } } }));
        const get = vi.fn().mockResolvedValue(response({ ok: false, status: 403, text: 'management forbidden' }));
        const helper = new E2ESessionHelper(requestWith(post, get, vi.fn()));

        await expect(helper.createIsolatedUser('photographer')).rejects.toThrow(/Role lookup failed.*403.*management forbidden/s);
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('fails clearly when assigning the role and brand does not succeed', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce(loginResponse())
            .mockResolvedValueOnce(response({ ok: true, body: { user: { id: 'user-1' } } }));
        const get = vi.fn().mockResolvedValue(response({
            ok: true,
            body: [{ id: 'role-1', name: 'photographer' }],
        }));
        const put = vi.fn().mockResolvedValue(response({ ok: false, status: 422, text: 'brand assignment rejected' }));
        const helper = new E2ESessionHelper(requestWith(post, get, put));

        await expect(helper.createIsolatedUser('photographer')).rejects.toThrow(/Failed to configure user.*422.*brand assignment rejected/s);
        expect(put).toHaveBeenCalledWith(
            '/api/management/users/user-1',
            expect.objectContaining({
                data: expect.objectContaining({ brand: 'rp' }),
            }),
        );
    });
});
