import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useAuth } from '../useAuth';
import {checkoutSessionStorageKey, loadOrCreateCheckoutSession} from '../checkoutSession';

vi.mock('swr', () => {
    const mutate = vi.fn();
    return {
        default: vi.fn(),
        mutate,
    };
});

import useSWR from 'swr';

describe('useAuth', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
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

    it('returns user data when available', () => {
        const mockUser = { id: 'u1', name: 'Test User', email: 'test@test.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: [] };

        vi.mocked(useSWR).mockReturnValue({
            data: mockUser,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useAuth());
        expect(result.current.isLoading).toBe(false);
        expect(result.current.user).toEqual(mockUser);
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

    it('logout calls fetch and revalidates', async () => {
        const mockMutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: { id: 'u1', name: 'T', email: 't@t.com', is_super_admin: false, is_admin: false, is_photographer: false, is_pending: false, can_edit_metadata: false, roles: [] },
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
        }));
        expect(sessionStorage.getItem(checkoutSessionStorageKey('u1'))).toBeNull();

        vi.unstubAllGlobals();
    });
});
