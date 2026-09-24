import useSWR, {mutate as globalMutate} from 'swr';
import {t} from "@lingui/core/macro";
import {fetcher, refreshAuthSession, type AuthMeUser, type User} from '../api';
import {clearCheckoutSession} from './checkoutSession';

// `useAuth` returns the /api/auth/me contract. Keep the broader `User`
// re-export for non-auth consumers that still import it from this module.
export type {AuthMeUser, User};

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

const fetchAuthMe = (key: string): Promise<AuthMeUser> => fetcher<AuthMeUser>(key);

export function useAuth() {
    const {data: user, error, isLoading, mutate} = useSWR<AuthMeUser>('/api/auth/me', fetchAuthMe, {
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
        const requestLogout = (): Promise<Response> => fetch('/api/auth/logout', {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            credentials: 'include'
        });

        let response: Response;
        try {
            response = await requestLogout();
            // An expired access cookie must not turn logout into a dead end.
            // The refresh endpoint owns the HttpOnly refresh credential; after
            // one successful rotation, retry the logout at most once.
            if (response.status === 401 && await refreshAuthSession()) {
                response = await requestLogout();
            }
        } catch (e) {
            throw new Error(e instanceof Error ? e.message : t`Logout fehlgeschlagen`, {cause: e});
        }

        if (!response.ok) {
            const data = await readAuthMessage(response);
            throw new Error(data.message || data.error || t`Logout fehlgeschlagen`);
        }

        if (user?.id) clearCheckoutSession(user.id);
        // Do not revalidate protected keys after logout: a stale 401 would
        // immediately start another refresh cycle even though the session is
        // intentionally being cleared.
        await globalMutate(() => true, undefined, {revalidate: false});
    };

    return {user, isLoading, isError: error, login, register, logout, mutate};
}
