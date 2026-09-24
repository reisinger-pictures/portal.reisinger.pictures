import { afterEach, describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useAuth, type AuthMeUser } from '../useAuth';
import {checkoutSessionStorageKey, loadOrCreateCheckoutSession} from '../checkoutSession';

vi.mock('swr', () => {
    const mutate = vi.fn();
    return {
        default: vi.fn(),
        mutate,
    };
});

import useSWR, {mutate as globalMutate} from 'swr';

const authMeUserFixture: AuthMeUser = {
    id: 'u1',
    guest_id: null,
    name: 'Test User',
    email: 'test@test.com',
    billing_name: 'Test User',
    billing_company: null,
    billing_street: null,
    billing_zip: null,
    billing_city: null,
    brand: 'rp',
    is_cross_brand: false,
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_org_admin: false,
    is_power_user: false,
    is_pending: false,
    can_edit_metadata: false,
    can_purchase_upgrades: false,
    roles: [],
    transient_galleries: [],
    transient_meta_galleries: [],
    photographer_gallery_groups: [],
};

describe('useAuth', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('returns loading state initially when no data', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useAuth());
        expect(result.current.isLoading).toBe(true);
        expect(result.current.user).toBeUndefined();
        expect(result.current.isError).toBeUndefined();
    });

    it('returns the complete AuthMeUser contract when available', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: authMeUserFixture,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useAuth());
        const typedUser: AuthMeUser | undefined = result.current.user;
        expect(result.current.isLoading).toBe(false);
        expect(typedUser).toEqual(authMeUserFixture);
        expect(result.current.isError).toBeUndefined();
    });

    it('returns error state on fetch error', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: new Error('Fetch failed'),
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useAuth());
        expect(result.current.isLoading).toBe(false);
        expect(result.current.user).toBeUndefined();
        expect(result.current.isError).toEqual(new Error('Fetch failed'));
    });

    it('login calls fetch with correct parameters and revalidates', async () => {
        const mockMutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: mockMutate,
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));

        const { result } = renderHook(() => useAuth());
        await result.current.login('test@test.com', 'password123');

        expect(fetch).toHaveBeenCalledWith('/api/auth/login', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ email: 'test@test.com', password: 'password123' }),
        }));

        vi.unstubAllGlobals();
    });

    it('register calls fetch and returns success message', async () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ message: 'Registrierung erfolgreich' }),
        }));

        const { result } = renderHook(() => useAuth());
        const message = await result.current.register('Max', 'max@test.com');

        expect(fetch).toHaveBeenCalledWith('/api/auth/register', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ name: 'Max', email: 'max@test.com' }),
        }));
        expect(message).toBe('Registrierung erfolgreich');

        vi.unstubAllGlobals();
    });

    it('login surfaces the backend error message instead of a generic one', async () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 401,
            json: () => Promise.resolve({ message: 'Zugangsdaten ungültig' }),
        }));

        const { result } = renderHook(() => useAuth());
        await expect(result.current.login('a@b.com', 'secret')).rejects.toThrow('Zugangsdaten ungültig');

        vi.unstubAllGlobals();
    });

    it('login falls back to the generic message when the error body is not JSON', async () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.reject(new Error('not json')),
        }));

        const { result } = renderHook(() => useAuth());
        await expect(result.current.login('a@b.com', 'secret')).rejects.toThrow('Login fehlgeschlagen.');

        vi.unstubAllGlobals();
    });

    it('register surfaces the backend error message instead of crashing on a non-JSON body', async () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.reject(new Error('not json')),
        }));

        const { result } = renderHook(() => useAuth());
        await expect(result.current.register('Max', 'max@test.com')).rejects.toThrow('Registrierung fehlgeschlagen');

        vi.unstubAllGlobals();
    });

    it('logout calls fetch and clears the cached session without revalidating', async () => {
        const mockMutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: authMeUserFixture,
            error: undefined,
            isLoading: false,
            mutate: mockMutate,
        } as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));
        loadOrCreateCheckoutSession(
            'u1',
            'cart-v1-1111111111111111',
            () => '11111111-1111-4111-8111-111111111111',
            sessionStorage
        );

        const { result } = renderHook(() => useAuth());
        await result.current.logout();

        expect(fetch).toHaveBeenCalledWith('/api/auth/logout', expect.objectContaining({
            method: 'POST',
            credentials: 'include',
        }));
        expect(globalMutate).toHaveBeenCalledWith(expect.any(Function), undefined, {revalidate: false});
        expect(sessionStorage.getItem(checkoutSessionStorageKey('u1'))).toBeNull();

        vi.unstubAllGlobals();
    });

    it('settles into an unauthenticated state after logout without perpetual loading', async () => {
        let swrData: AuthMeUser | undefined = authMeUserFixture;
        let swrError: Error | undefined;
        const mockMutate = vi.fn();
        vi.mocked(useSWR).mockImplementation(() => ({
            data: swrData,
            error: swrError,
            isLoading: false,
            mutate: mockMutate,
        }) as never);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ok: true}));
        const {result, rerender} = renderHook(() => useAuth());

        vi.mocked(globalMutate).mockImplementationOnce(async () => {
            // Simulate SWR applying the logout cache mutation: auth data and
            // errors are cleared, while revalidation remains disabled.
            swrData = undefined;
            swrError = undefined;
        });
        await result.current.logout();
        rerender();

        expect(result.current.user).toBeUndefined();
        expect(result.current.isError).toBeUndefined();
        expect(result.current.isLoading).toBe(false);
        expect(globalMutate).toHaveBeenCalledWith(expect.any(Function), undefined, {revalidate: false});
    });

    it('refreshes an expired access session once before retrying logout', async () => {
        vi.mocked(useSWR).mockReturnValue({
            data: authMeUserFixture,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({error: 'Unauthenticated.'}), {
                status: 401,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({success: true}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({message: 'Successfully logged out'}), {
                status: 200,
                headers: {'Content-Type': 'application/json'}
            }));
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useAuth());
        await result.current.logout();

        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls.map(([input]) => input)).toEqual([
            '/api/auth/logout',
            '/api/auth/refresh',
            '/api/auth/logout',
        ]);
        expect(fetchMock.mock.calls[1][1]).toEqual(expect.objectContaining({
            method: 'POST',
            credentials: 'include',
        }));
        expect(globalMutate).toHaveBeenCalledWith(expect.any(Function), undefined, {revalidate: false});
    });
});
