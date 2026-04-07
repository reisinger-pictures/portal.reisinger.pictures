let isRefreshing = false;
let refreshPromise: Promise<boolean> | null = null;

export type GlobalErrorCallback = (status: number, message: string) => void;
let globalErrorCallback: GlobalErrorCallback | null = null;
export const setGlobalErrorCallback = (cb: GlobalErrorCallback) => { globalErrorCallback = cb; };


const refreshToken = async (): Promise<boolean> => {
    if (isRefreshing && refreshPromise) return refreshPromise;

    isRefreshing = true;
    refreshPromise = (async () => {
        try {
            const res = await fetch('/api/auth/refresh', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });

            return res.ok;
        } catch {
            return false;
        } finally {
            isRefreshing = false;
            refreshPromise = null;
        }
    })();

    return refreshPromise;
};

const getHeaders = (): Record<string, string> => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json'
});

const handleApiError = async (res: Response) => {
    let errorMsg = `HTTP Fehler ${res.status}`;
    let errorInfo = null;
    const contentType = res.headers.get('content-type');

    if (contentType && contentType.includes('application/json')) {
        try {
            errorInfo = await res.json();
            errorMsg = errorInfo.error || errorInfo.message || errorMsg;
        } catch (e) { console.debug('Failed to parse error', e); }
    } else {
        try {
            const text = await res.text();
            if (text.includes('<title>')) {
                const match = text.match(/<title>(.*?)<\/title>/i);
                if (match) errorMsg = match[1];
            } else if (text) {
                errorMsg = text.substring(0, 150);
            }
            errorInfo = { text };
        } catch (e) { console.debug('Failed to parse error', e); }
    }
    
    const error = new Error(errorMsg) as Error & { info?: unknown; status?: number };
    error.info = errorInfo;
    error.status = res.status;

    if (globalErrorCallback && (res.status >= 500 || res.status === 0)) {
        globalErrorCallback(res.status, errorMsg);
    }

    throw error;
};

export const fetcher = async <T>(url: string): Promise<T> => {
    let res: Response;
    try {
        res = await fetch(url, { headers: getHeaders(), credentials: 'include' });
    } catch {
        const error = new Error('Netzwerkfehler: Keine Verbindung zum Server.') as Error & { status?: number };
        error.status = 0;
        if (globalErrorCallback) globalErrorCallback(0, error.message);
        throw error;
    }

    if (res.status === 401 && !url.includes('/api/auth/')) {
        const success = await refreshToken();
        if (success) {
            res = await fetch(url, { headers: getHeaders(), credentials: 'include' });
        }
    }

    if (!res.ok) {
        await handleApiError(res);
    }

    return res.json() as Promise<T>;
};

export const apiMutate = async <T>(url: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<T> => {
    let res: Response;
    try {
        res = await fetch(url, {
            method,
            headers: getHeaders(),
            credentials: 'include',
            body: body ? JSON.stringify(body) : undefined
        });
    } catch {
        const error = new Error('Netzwerkfehler: Keine Verbindung zum Server.') as Error & { status?: number };
        error.status = 0;
        if (globalErrorCallback) globalErrorCallback(0, error.message);
        throw error;
    }

    if (res.status === 401 && !url.includes('/api/auth/')) {
        const success = await refreshToken();
        if (success) {
            res = await fetch(url, {
                method,
                headers: getHeaders(),
                credentials: 'include',
                body: body ? JSON.stringify(body) : undefined
            });
        }
    }

    if (!res.ok) {
        await handleApiError(res);
    }

    const text = await res.text();
    return text ? JSON.parse(text) : {} as T;
};
