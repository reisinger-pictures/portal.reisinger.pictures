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

/**
 * The unit guard on the E2E session helper.
 *
 * Without it the helper is unit-blind: it accepts `'50'` and `50` for
 * `calc_base_price` alike, so after the 2026-09-28 euros → cents change every
 * calculator spec would still pass — the fixture writes euros into a cents
 * field, the assertion reads euros back out of it, and the two errors cancel.
 * These cases fail if the guard is ever removed.
 */
describe('E2ESessionHelper calculator money settings are unit-checked', () => {
    const settingsPayload = (money: Record<string, unknown>) => ({
        calc_base_price: 5000,
        calc_hourly_rate: 8000,
        calc_images_per_hour: '6',
        calc_outdoor_images_per_hour: '8',
        calc_flatrate_multiplier: '1.2',
        ...money,
    });

    const helperFor = (payload: Record<string, unknown>) => {
        const post = vi.fn().mockResolvedValue(loginResponse());
        const get = vi.fn().mockResolvedValue(response({ ok: true, body: payload }));
        return new E2ESessionHelper(requestWith(post, get, vi.fn()));
    };

    it('accepts integer cent amounts', async () => {
        await expect(helperFor(settingsPayload({})).getShootingCalculatorSettings()).resolves.toEqual({
            calc_base_price: 5000,
            calc_hourly_rate: 8000,
            calc_images_per_hour: '6',
            calc_outdoor_images_per_hour: '8',
            calc_flatrate_multiplier: '1.2',
        });
    });

    it.each([
        ['the old euro value as text', '50'],
        ['a sub-cent amount as text', '50.5'],
        ['a fractional cent amount', 5000.5],
    ])('rejects %s for calc_base_price as not an integer cent amount', async (_name, value) => {
        await expect(helperFor(settingsPayload({ calc_base_price: value })).getShootingCalculatorSettings())
            .rejects.toThrow(/"calc_base_price" is money and must be integer cents/s);
    });

    it.each([
        ['the old euro value as a number', 50],
        ['an amount just below the minimum', 499],
    ])('rejects %s as a euro amount written into a cents field', async (_name, value) => {
        // A *numeric* euro amount is a well-formed integer that simply sits
        // below the endpoint's own `min:500`, so the guard reports it as what it
        // is rather than as a type problem. Both spellings of "50 €" are
        // rejected; only the diagnosis differs.
        await expect(helperFor(settingsPayload({ calc_base_price: value })).getShootingCalculatorSettings())
            .rejects.toThrow(/"calc_base_price" is \d+ cents, below the 500-cent minimum/s);
    });

    it('rejects an explicit null as a missing value', async () => {
        await expect(helperFor(settingsPayload({ calc_base_price: null })).getShootingCalculatorSettings())
            .rejects.toThrow(/calc_base_price" is missing from/);
    });

    it('does not apply the money rule to the counts and the factor', async () => {
        // `calc_images_per_hour` and `calc_flatrate_multiplier` are not money:
        // a count and a dimensionless factor keep their stored text, and a
        // blanket "everything numeric" rule would reject the factor's '1.2'.
        await expect(helperFor(settingsPayload({
            calc_images_per_hour: '12',
            calc_flatrate_multiplier: '1.35',
        })).getShootingCalculatorSettings()).resolves.toMatchObject({
            calc_images_per_hour: '12',
            calc_flatrate_multiplier: '1.35',
        });
    });
});
