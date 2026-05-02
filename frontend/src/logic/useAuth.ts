import useSWR, {mutate as globalMutate} from 'swr';
import {fetcher} from '../api';
import {Gallery} from './useGalleries';

export interface User {
    id: string;
    name: string;
    email: string;
    billing_name?: string | null;
    billing_company?: string | null;
    billing_street?: string | null;
    billing_zip?: string | null;
    billing_city?: string | null;
    metadata_copyright?: string | null;
    ftp_slug?: string | null;
    is_super_admin: boolean;
    is_admin: boolean;
    is_photographer: boolean;
    is_pending: boolean;
    can_edit_metadata: boolean;
    flatrate_level?: 'none' | 'web' | 'print' | 'original';
    can_purchase_upgrades?: boolean;
    is_customer_manager?: boolean;
    is_power_user?: boolean;
    roles: string[];
    missing_watermark?: boolean;
    transient_meta_galleries?: string[];
    my_galleries?: Gallery[];
    photographer_galleries?: Gallery[];
}

export function useAuth() {
    const {data: user, error, isLoading, mutate} = useSWR<User>('/api/auth/me', fetcher, {
        shouldRetryOnError: false,
    });

    const login = async (email: string, password: string): Promise<void> => {
        const response = await fetch('/api/auth/login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            credentials: 'include',
            body: JSON.stringify({email, password})
        });
        if (!response.ok) throw new Error('Login fehlgeschlagen');
        await globalMutate(() => true, undefined, {revalidate: true});
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
            throw new Error(e instanceof Error ? e.message : 'Logout fehlgeschlagen', {cause: e});
        }
        await globalMutate(() => true, undefined, {revalidate: true});
    };

    return {user, isLoading: isLoading || (!user && !error), isError: error, login, register, logout, mutate};
}
