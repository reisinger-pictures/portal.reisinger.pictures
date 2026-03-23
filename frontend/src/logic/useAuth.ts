import useSWR from 'swr';
import {fetcher} from '../api';
import {Gallery} from './useGalleries';

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_photographer: boolean;
    is_pending: boolean;
    can_edit_metadata: boolean;
    roles: string[];
    my_galleries?: Gallery[];
}

export function useAuth() {
    const {data: user, error, mutate} = useSWR<User>('/api/auth/me', fetcher, {
        shouldRetryOnError: false,
    });

    if (user && user.roles && (window as any).__loggedUserId !== user.id) {
        console.log('User Roles:', user.roles);
        (window as any).__loggedUserId = user.id;
    }

    const login = async (email: string, password: string): Promise<void> => {
        const response = await fetch('/api/auth/login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({email, password})
        });
        if (!response.ok) throw new Error('Login fehlgeschlagen');
        await mutate();
    };

    const register = async (name: string, email: string): Promise<string> => {
        const response = await fetch('/api/auth/register', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({name, email})
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || data.error || 'Registrierung fehlgeschlagen');
        return data.message || 'Erfolgreich registriert';
    };

    const logout = async (): Promise<void> => {
        try {
            await fetch('/api/auth/logout', { 
                method: 'POST', 
                headers: {'Accept': 'application/json'},
                credentials: 'include' 
            });
        } catch (e) {
            console.error('Logout Fehler', e);
        }
        // WICHTIG: Komplettes Revalidate erzwingen, damit SWR in den Error-State (401) wechselt
        await mutate(); 
    };

    return {user, isLoading: !error && user === undefined, isError: error, login, register, logout};
}
