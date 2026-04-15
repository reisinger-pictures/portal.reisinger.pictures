let isRefreshing = false;
let refreshPromise: Promise<boolean> | null = null;

export type GlobalErrorCallback = (status: number, message: string) => void;
export interface ApiError extends Error {
    status?: number;
    info?: unknown;
}
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
    let errorInfo;
    const contentType = res.headers.get('content-type');

    if (contentType && contentType.includes('application/json')) {
        try {
            errorInfo = await res.json();
            errorMsg = errorInfo.error || errorInfo.message || errorMsg;
        } catch (parseError) { errorInfo = { parseError: String(parseError) }; }
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
        } catch (parseError) { errorInfo = { parseError: String(parseError) }; }
    }
    
    const error = new Error(errorMsg) as ApiError;
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
        const error = new Error('Netzwerkfehler: Keine Verbindung zum Server.') as ApiError;
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

    const contentType = res.headers.get('content-type');
    if (contentType && contentType.includes('application/json')) {
        return res.json() as Promise<T>;
    }
    
    throw new Error('Server hat kein valides JSON zurückgegeben.');
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
        const error = new Error('Netzwerkfehler: Keine Verbindung zum Server.') as ApiError;
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

    const contentType = res.headers.get('content-type');
    const text = await res.text();
    if (contentType && contentType.includes('application/json')) {
        try {
            return text ? JSON.parse(text) : {} as T;
        } catch {
            throw new Error('Server-Antwort konnte nicht als JSON verarbeitet werden.');
        }
    }
    
    return {} as T;
};

// --- Global Data Contracts ---
export interface Customer { id: string; name: string; company?: string | null; email?: string | null; street?: string | null; zip?: string | null; city?: string | null; country?: string | null; uid?: string | null; }
export interface Product { id: string; type: 'item' | 'discount_fixed' | 'discount_percent'; name: string; description?: string | null; price: number; }
export interface TextSnippet { id: string; title: string; shortcut?: string | null; content_html: string; }
export interface OrderItem { photoId?: string; tier: string; price: number; filename?: string; notes?: string; qty?: number; row_total?: number; type?: string; description?: string; calculated_percentage?: number; }
export interface InvoiceItem { type: string; description: string; notes: string; qty: number; price: number; row_total?: number; filename?: string; tier?: string; }
export interface InvoiceDiscount { type: string; description: string; notes: string; price: number; calculated_percentage?: number; row_total?: number; filename?: string; tier?: string; }
export interface InvoiceSnapshot { invoice_number: string; total_gross: string | number; created_at: string; customer_details: { name?: string; company?: string; items: OrderItem[]; quote_message?: string; email?: string; street?: string; zip?: string; city?: string; country?: string; uid?: string; terms_html?: string; due_date?: string; }; }
export interface Order { id: string; status: string; is_quote_request: boolean | number; total_amount: string | number; created_at: string; user?: { name: string; email: string; }; invoice_snapshot?: InvoiceSnapshot; }
export interface CheckoutResponse { success?: boolean; requires_action?: boolean; client_secret?: string; invoice_number: string; order_id?: string; }
export interface RedeemInviteResponse { full_path?: string; message?: string; requires_mail_verification?: boolean; }
export interface SendMailResponse { success: boolean; notified_count: number; }
export interface GenerateInviteResponse { success: boolean; link: string; }
export interface InviteData { id: string; name: string; token: string; }
export interface RatingData { lr_uuid?: string; filename?: string; avg_rating?: number; all_comments?: string; thumb_url?: string; user_id?: string; name?: string; email?: string; rated_count?: number; }
export interface MailpitMessage { ID: string; To: { Address: string }[]; HTML?: string; }
export interface SystemInfo { laravel_build_time: string; php_version: string; laravel_version: string; db_version?: string; }
export interface BreadcrumbItem { name: string; type?: string; full_path?: string; }
