let isRefreshing = false;
let refreshPromise: Promise<boolean> | null = null;

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
        } catch (e) {
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
    const contentType = res.headers.get('content-type');

    if (contentType && contentType.includes('application/json')) {
        try {
            const errData = await res.json();
            errorMsg = errData.error || errData.message || errorMsg;
        } catch {}
    } else {
        try {
            const text = await res.text();
            if (text.includes('<title>')) {
                const match = text.match(/<title>(.*?)<\/title>/i);
                if (match) errorMsg = match[1];
            } else if (text) {
                errorMsg = text.substring(0, 150);
            }
        } catch {}
    }
    throw new Error(errorMsg);
};

export const fetcher = async <T>(url: string): Promise<T> => {
    let res = await fetch(url, { headers: getHeaders(), credentials: 'include' });

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
    let res = await fetch(url, {
        method,
        headers: getHeaders(),
        credentials: 'include',
        body: body ? JSON.stringify(body) : undefined
    });

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
