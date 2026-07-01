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
export interface Gallery {
    id: string;
    name: string;
    slug: string;
    full_path: string;
    type: 'selection' | 'delivery';
    is_live: boolean;
    is_public: boolean;
    is_free_download?: boolean | null;
    is_editorial_only?: boolean | null;
    is_hidden?: boolean | null;
    restricted_photographers?: boolean | null;
    effective_restricted_photographers?: boolean;
    effective_is_free_download?: boolean;
    allow_client_metadata_edit?: boolean;
    apply_metadata_to_photos?: boolean;
    allow_custom_quotes?: boolean;
    default_title?: string;
    default_description?: string;
    default_keywords?: string;
    default_location?: string;
    default_city?: string;
    default_state?: string;
    default_country?: string;
    default_iso_country?: string;
    gallery_group_id?: string | null;
    expires_at?: string | null;
    created_at?: string;
    tenant_id?: string | null;
    brand?: string | null;
}

// Canonical auth-context user (`/api/auth/me`). `roles` is the role-name list.
// Use `UserDetailed` (logic/useUsers) for the management endpoint's richer shape.
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
    ai_is_unconfigured?: boolean;
    transient_meta_galleries?: string[];
    my_galleries?: Gallery[];
    photographer_galleries?: Gallery[];
}
export interface TextSnippet { id: string; title: string; shortcut?: string | null; content_html: string; }
export interface OrderItem { id?: string; order_id?: string; photo_id?: string; tier: string; price: number; use_case_id?: string; qty?: number; filename?: string; notes?: string; row_total?: number; type?: string; description?: string; calculated_percentage?: number; }
export interface InvoiceItem { type: string; description: string; notes: string; qty: number; price: number; row_total?: number; filename?: string; tier?: string; }
export interface InvoiceDiscount { type: string; description: string; notes: string; price: number; calculated_percentage?: number; row_total?: number; filename?: string; tier?: string; }

export interface DocumentFormData {
    type: string;
    invoice_number: string;
    date: string;
    due_date: string;
    service_date: string;
    validity: string;
    customer_name: string;
    customer_company: string;
    customer_street: string;
    customer_zip: string;
    customer_city: string;
    customer_country: string;
    customer_email: string;
    customer_uid: string;
    terms_html: string;
    [key: string]: string;
}

export interface InvoiceCustomerDetails {
    items?: OrderItem[];
    quote_message?: string;
    [key: string]: unknown;
}

export interface InvoiceSnapshot { id?: string; invoice_number: string; total_gross: string | number; total_net: string | number; tax_rate: number; created_at: string; customer_details: string | InvoiceCustomerDetails; }
export interface Order { id: string; user_id?: string; status: string; is_quote_request: boolean | number; total_net: string | number; total_gross: string | number; tax_rate: number; payment_method?: string; billing_name?: string; billing_company?: string; billing_street?: string; billing_zip?: string; billing_city?: string; stripe_payment_intent_id?: string; created_at: string; updated_at: string; user?: { id?: string; name?: string; email?: string; }; invoice_snapshot?: InvoiceSnapshot; items?: OrderItem[]; }
export interface CheckoutResponse { success?: boolean; requires_action?: boolean; client_secret?: string; invoice_number: string; order_id?: string; }
export interface RedeemInviteResponse { full_path?: string; message?: string; requires_mail_verification?: boolean; }
export interface SendMailResponse { success: boolean; notified_count: number; }
export interface GenerateInviteResponse { success: boolean; link: string; }
export interface InviteData { id: string; name: string; token: string; }
export interface RatingData { lr_uuid?: string; filename?: string; avg_rating?: number; all_comments?: string; thumb_url?: string; user_id?: string; name?: string; email?: string; rated_count?: number; }
export interface MailpitMessage { ID: string; To: { Address: string }[]; HTML?: string; }
export interface SystemInfo { laravel_build_time: string; php_version: string; laravel_version: string; db_version?: string; }
export interface BreadcrumbItem { name: string; type?: string; full_path?: string; }
