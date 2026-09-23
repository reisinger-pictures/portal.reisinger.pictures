import useSWR, {mutate as globalMutate} from 'swr';
import {t} from "@lingui/core/macro";
import {fetcher, User} from '../api';
import {clearCheckoutSession} from './checkoutSession';

// Re-export the canonical `User` type so existing imports from this module keep working.
export type {User};

interface AuthMessagePayload {
    message?: string;
    error?: string;
}

/**
 * Reads the JSON body defensively. If the response is not JSON (e.g. an HTML
 * error page from a proxy), an empty object is returned instead of throwing a
 * `SyntaxError` that would mask the real HTTP error.
 */
async function readAuthMessage(response: Response): Promise<AuthMessagePayload> {
    try {
        return (await response.json()) as AuthMessagePayload;
    } catch {
        return {};
    }
}

export function useAuth() {
    const {data: user, error, isLoading, mutate} = useSWR<User>('/api/auth/me', fetcher, {
        shouldRetryOnError: false,
        dedupingInterval: 60_000,
    });

    const login = async (email: string, password: string): Promise<void> => {
        const response = await fetch('/api/auth/login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({email, password})
        });
        const data = await readAuthMessage(response);
        if (!response.ok) throw new Error(data.message || data.error || t`Login fehlgeschlagen.`);
        await globalMutate(() => true, undefined, {revalidate: true});
    };

    const register = async (name: string, email: string): Promise<string> => {
        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({name, email})
        });
        const data = await readAuthMessage(response);
        if (!response.ok) throw new Error(data.message || data.error || t`Registrierung fehlgeschlagen`);
        return data.message || t`Erfolgreich registriert`;
    };

    const logout = async (): Promise<void> => {
        try {
            await fetch('/api/auth/logout', {
                method: 'POST',
                headers: {'Accept': 'application/json'},
                credentials: 'include'
            });
        } catch (e) {
            throw new Error(e instanceof Error ? e.message : t`Logout fehlgeschlagen`, {cause: e});
        }
        if (user?.id) clearCheckoutSession(user.id);
        await globalMutate(() => true, undefined, {revalidate: true});
    };

    return {user, isLoading: isLoading || (!user && !error), isError: error, login, register, logout, mutate};
}
