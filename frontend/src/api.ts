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
                credentials: 'include' // Sende Cookies (altes Token) mit
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

export const fetcher = async <T>(url: string): Promise<T> => {
    let res = await fetch(url, { headers: getHeaders(), credentials: 'include' });

    // Intercept 401 Unauthorized for silent refresh
    if (res.status === 401 && !url.includes('/api/auth/')) {
        const success = await refreshToken();
        if (success) {
            res = await fetch(url, { headers: getHeaders(), credentials: 'include' });
        }
    }

    if (!res.ok) throw new Error('API Error');
    return res.json() as Promise<T>;
};

export const apiMutate = async <T>(url: string, method: 'POST' | 'PUT' | 'DELETE', body?: unknown): Promise<T> => {
    let res = await fetch(url, {
        method,
        headers: getHeaders(),
        credentials: 'include',
        body: body ? JSON.stringify(body) : undefined
    });

    // Intercept 401 Unauthorized for silent refresh
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

    if (!res.ok) throw new Error('API Error');
    const text = await res.text();
    return text ? JSON.parse(text) : {} as T;
};
