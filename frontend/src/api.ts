let refreshPromise: Promise<boolean> | null = null;

const REFRESH_ENDPOINT = '/api/auth/refresh';
/**
 * Auth bootstrap/termination requests are the only generic-helper exceptions.
 * Retrying them from their own 401 would recurse into refresh (or create a
 * pointless second login/logout attempt); `useAuth.logout` owns its one-shot
 * refresh explicitly.
 */
const AUTH_ENDPOINTS_WITHOUT_REFRESH = new Set([
    '/api/auth/refresh',
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/logout',
    '/api/auth/reset-password',
]);

/**
 * Keep auth-flow classification independent of query strings and trailing
 * slashes. The browser owns the refresh credential; it must only be sent by
 * the browser with `credentials: 'include'` and is never inspected by JS.
 */
const normalizedApiPath = (url: string): string => {
    try {
        return new URL(url, 'http://localhost').pathname.replace(/\/+$/, '') || '/';
    } catch {
        return url.split(/[?#]/, 1)[0]?.replace(/\/+$/, '') || '/';
    }
};

const shouldAttemptRefresh = (url: string): boolean =>
    !AUTH_ENDPOINTS_WITHOUT_REFRESH.has(normalizedApiPath(url));

/**
 * Fetch rejects with an AbortError when a caller cancels a request.  It is a
 * control-flow signal, not a transport failure, so API helpers must rethrow it
 * unchanged instead of normalising it into the generic network error.
 */
const isAbortError = (error: unknown): boolean =>
    typeof error === 'object' && error !== null && 'name' in error && error.name === 'AbortError';

const isJsonObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

const NETWORK_ERROR_MESSAGE = 'Netzwerkfehler: Keine Verbindung zum Server.';

/**
 * A signal can be aborted while a fetch mock (or a cached response) resolves
 * successfully. Check the signal at the boundaries so a cancelled caller is
 * never allowed to continue into refresh, retry, or response parsing.
 */
const throwIfAborted = (signal: AbortSignal | undefined): void => {
    if (!signal?.aborted) return;
    if (signal.reason !== undefined) throw signal.reason;
    throw new DOMException('The operation was aborted.', 'AbortError');
};

export type GlobalErrorCallback = (status: number, message: string) => void;
export interface ApiError extends Error {
    status?: number;
    info?: unknown;
}
let globalErrorCallback: GlobalErrorCallback | null = null;
export const setGlobalErrorCallback = (cb: GlobalErrorCallback | null) => { globalErrorCallback = cb; };


export const refreshAuthSession = (): Promise<boolean> => {
    if (refreshPromise) return refreshPromise;

    const request = (async (): Promise<boolean> => {
        try {
            const res = await fetch(REFRESH_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });

            return res.ok;
        } catch (error) {
            if (isAbortError(error)) throw error;
            return false;
        }
    })();

    refreshPromise = request;
    // The refresh is single-flight. Cleanup is registered after assigning the
    // promise so even a synchronously throwing fetch mock cannot leave stale
    // state behind.  The rejection callback is intentional: preserving an
    // AbortError must not create an unhandled rejection in the bookkeeping
    // promise.
    const clearRefreshPromise = () => {
        if (refreshPromise === request) {
            refreshPromise = null;
        }
    };
    void request.then(clearRefreshPromise, clearRefreshPromise);

    return request;
};

/**
 * All authenticated request helpers use this boundary for their initial and
 * retry fetches. Keeping the conversion in one place prevents a raw transport
 * exception (especially on a retry) from bypassing the global error channel.
 * AbortError is deliberately rethrown by identity: it is control flow, not a
 * transport failure.
 */
const fetchWithNetworkErrorHandling = async (url: string, init: RequestInit): Promise<Response> => {
    throwIfAborted(init.signal ?? undefined);
    try {
        return await fetch(url, init);
    } catch (error) {
        if (isAbortError(error)) throw error;
        if (init.signal?.aborted) throwIfAborted(init.signal);
        const networkError = new Error(NETWORK_ERROR_MESSAGE) as ApiError;
        networkError.status = 0;
        globalErrorCallback?.(0, networkError.message);
        throw networkError;
    }
};

/**
 * Run a request and, at most once, refresh an expired portal session and
 * replay it. The refresh is intentionally shared without inheriting a
 * caller's AbortSignal: cancelling one request must not cancel refreshes used
 * by other callers. The caller's signal is checked before and after the shared
 * refresh so that caller is stopped at the correct boundary.
 */
const fetchWithRefresh = async (url: string, init: RequestInit): Promise<Response> => {
    const signal = init.signal ?? undefined;
    let res = await fetchWithNetworkErrorHandling(url, init);

    if (res.status === 401 && shouldAttemptRefresh(url)) {
        throwIfAborted(signal);
        const success = await refreshAuthSession();
        throwIfAborted(signal);
        if (success) {
            res = await fetchWithNetworkErrorHandling(url, init);
            throwIfAborted(signal);
        }
    }

    throwIfAborted(signal);
    return res;
};

const getHeaders = (): Record<string, string> => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json'
});

const handleApiError = async (res: Response) => {
    let errorMsg = `HTTP Fehler ${res.status}`;
    let errorInfo: unknown;
    const contentType = res.headers.get('content-type');

    if (contentType && contentType.includes('application/json')) {
        try {
            const parsed: unknown = await res.json();
            if (isJsonObject(parsed)) {
                errorInfo = parsed;
                if (typeof parsed.error === 'string' && parsed.error.length > 0) {
                    errorMsg = parsed.error;
                } else if (typeof parsed.message === 'string' && parsed.message.length > 0) {
                    errorMsg = parsed.message;
                }
            } else {
                errorInfo = {body: parsed};
            }
        } catch (parseError) {
            if (isAbortError(parseError)) throw parseError;
            errorInfo = { parseError: String(parseError) };
        }
    } else {
        // Non-JSON error bodies are frequently produced by proxies, PHP
        // warnings or framework debug output and can contain internal details
        // (stack traces, SQL, paths). Drain the body for connection hygiene,
        // but never promote it to a user-visible error message or error info.
        try {
            await res.text();
        } catch (parseError) {
            if (isAbortError(parseError)) throw parseError;
        }
    }
    
    const error = new Error(errorMsg) as ApiError;
    error.info = errorInfo;
    error.status = res.status;

    throw error;
};

export interface ApiRequestOptions {
    signal?: AbortSignal;
}

export const fetcher = async <T>(url: string, options: ApiRequestOptions = {}): Promise<T> => {
    const requestOptions: RequestInit = {
        headers: getHeaders(),
        credentials: 'include',
        ...(options.signal ? { signal: options.signal } : {})
    };
    const res = await fetchWithRefresh(url, requestOptions);

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
    
    throw new Error('Server hat kein valides JSON zurückgegeben.');
};

/**
 * Multipart counterpart of `apiMutate`. Sends a `FormData` body without a
 * `Content-Type` header so the browser can set the multipart boundary.
 * Reuses the same 401-refresh and error-normalisation paths as `fetcher`.
 * The typed FormData contract keeps the body replayable for the one retry;
 * callers must not substitute a one-shot stream here.
 */
export const apiUpload = async <T>(url: string, body: FormData, options: ApiRequestOptions = {}): Promise<T> => {
    const requestOptions: RequestInit = {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        credentials: 'include',
        body,
        ...(options.signal ? { signal: options.signal } : {})
    };
    const res = await fetchWithRefresh(url, requestOptions);

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

    throw new Error('Server hat kein valides JSON zurückgegeben.');
};

export interface ApiMutateOptions extends ApiRequestOptions {
    headers?: Record<string, string>;
}

export const apiMutate = async <T>(
    url: string,
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    body?: unknown,
    options: ApiMutateOptions = {}
): Promise<T> => {
    const requestOptions: RequestInit = {
        method,
        headers: {...getHeaders(), ...options.headers},
        credentials: 'include',
        body: body ? JSON.stringify(body) : undefined,
        ...(options.signal ? { signal: options.signal } : {})
    };
    const res = await fetchWithRefresh(url, requestOptions);

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
    
    throw new Error('Server hat kein valides JSON zurückgegeben.');
};

export interface DownloadedFile {
    blob: Blob;
    /** Server-provided filename from `Content-Disposition`, if present. */
    filename: string | null;
}

/**
 * Parse the `filename` parameter of a `Content-Disposition` header. The
 * RFC 5987 extended form (`filename*=UTF-8''…`) takes precedence over the
 * plain `filename=…` form.
 */
export function filenameFromContentDisposition(header: string | null): string | null {
    if (!header) return null;

    const extended = header.match(/filename\*=UTF-8''([^;]+)/i);
    if (extended) {
        try {
            return decodeURIComponent(extended[1].trim().replace(/^"|"$/g, ''));
        } catch {
            return null;
        }
    }

    const simple = header.match(/filename="?([^";]+)"?/i);
    return simple ? simple[1].trim() : null;
}

export interface ApiDownloadOptions extends ApiRequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    headers?: Record<string, string>;
    body?: BodyInit | null;
}

/**
 * Authenticated binary download (e.g. PDF streams). Unlike `fetcher`, it
 * returns the raw `Blob` plus the server-provided filename and reuses the same
 * 401-refresh and error-normalisation paths. The optional method/body keeps
 * binary generation endpoints on that same pipeline without weakening the
 * JSON-only `apiMutate` contract.
 */
export const apiDownload = async (
    url: string,
    options: ApiDownloadOptions = {}
): Promise<DownloadedFile> => {
    const requestOptions: RequestInit = {
        headers: {
            'Accept': 'application/pdf, application/octet-stream',
            ...options.headers
        },
        credentials: 'include',
        ...(options.method ? { method: options.method } : {}),
        ...(options.body !== undefined ? { body: options.body } : {}),
        ...(options.signal ? { signal: options.signal } : {})
    };

    const res = await fetchWithRefresh(url, requestOptions);

    if (!res.ok) {
        await handleApiError(res);
    }

    const blob = await res.blob();
    return { blob, filename: filenameFromContentDisposition(res.headers.get('content-disposition')) };
};

// --- Global Data Contracts ---
export interface Customer { id: string; name: string; company?: string | null; email?: string | null; birthdate?: string | null; street?: string | null; zip?: string | null; city?: string | null; country?: string | null; uid?: string | null; }
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
    org_ids?: string[];
    brand?: string | null;
    licensing_mode?: string | null;
    effective_licensing_mode?: string;
    volume_preset_id?: number | null;
}

export interface VolumePresetTier {
    position: number;
    min_quantity: number;
    price_cents: number;
}

export interface VolumePreset {
    /** `volume_presets.id` — a bigint primary key, delivered as a JSON number. */
    id: number;
    name: string;
    is_default: boolean;
    tiers: VolumePresetTier[];
}

// Canonical auth-context user (`/api/auth/me`). `roles` is the role-name list.
// Use `UserDetailed` (logic/useUsers) for the management endpoint's richer shape.
export interface User {
    id: string;
    guest_id?: string | null;
    name: string;
    email: string;
    billing_name?: string | null;
    billing_company?: string | null;
    billing_street?: string | null;
    billing_zip?: string | null;
    billing_city?: string | null;
    metadata_copyright?: string | null;
    ftp_slug?: string | null;
    brand?: string | null;
    is_cross_brand?: boolean;
    is_super_admin: boolean;
    is_admin: boolean;
    is_photographer: boolean;
    is_org_admin?: boolean;
    is_power_user?: boolean;
    is_pending: boolean;
    can_edit_metadata: boolean;
    flatrate_level?: 'none' | 'web' | 'print' | 'original';
    can_purchase_upgrades?: boolean;
    roles: string[];
    missing_watermark?: boolean;
    ai_is_unconfigured?: boolean;
    transient_galleries?: string[];
    transient_meta_galleries?: string[];
    my_galleries?: Gallery[];
    photographer_galleries?: Gallery[];
    photographer_gallery_groups?: Array<{ id: string; name: string }>;
}

/** Fields guaranteed by the `/api/auth/me` contract. */
export interface AuthMeUser extends User {
    guest_id: string | null;
    billing_name: string | null;
    billing_company: string | null;
    billing_street: string | null;
    billing_zip: string | null;
    billing_city: string | null;
    brand: string | null;
    is_cross_brand: boolean;
    is_org_admin: boolean;
    is_power_user: boolean;
    can_purchase_upgrades: boolean;
    transient_galleries: string[];
    transient_meta_galleries: string[];
    photographer_gallery_groups: Array<{ id: string; name: string }>;
}
export interface TextSnippet { id: string; title: string; shortcut?: string | null; content_html: string; }
export interface OrderItem { id?: string; order_id?: string; photo_id?: string; tier: string; price: number; use_case_id?: string; qty?: number; filename?: string; notes?: string; row_total?: number; type?: string; description?: string; calculated_percentage?: number | string; }
export interface InvoiceItem { type: string; description: string; notes: string; qty: number; price: number; row_total?: number; filename?: string; tier?: string; }
export interface InvoiceDiscount { type: string; description: string; notes: string; price: number; calculated_percentage?: number | string; row_total?: number; filename?: string; tier?: string; }

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
export interface Order { id: string; user_id?: string; status: string; is_quote_request: boolean | number; total_net?: string | number; total_gross?: string | number; tax_rate: number; payment_method?: string; billing_name?: string; billing_company?: string; billing_street?: string; billing_zip?: string; billing_city?: string; created_at: string; updated_at: string; user?: { id?: string; name?: string; email?: string; }; invoice_snapshot?: InvoiceSnapshot; items?: OrderItem[]; }
export interface CheckoutResponse { success?: boolean; payment_pending?: boolean; requires_action?: boolean; client_secret?: string; invoice_number?: string | null; order_id?: string; status?: string; poll_url?: string; }
export interface RedeemInviteResponse { full_path?: string; message?: string; requires_mail_verification?: boolean; }
export interface SendMailResponse { success: boolean; notified_count: number; }
export interface TestEmailResponse { success: boolean; sent_to: string; }
export interface GenerateInviteResponse { success: boolean; link: string; }
export interface InviteData { id: string; name: string; token: string; }
export interface RatingData { lr_uuid?: string; filename?: string; avg_rating?: number; all_comments?: string; thumb_url?: string; user_id?: string; name?: string; email?: string; rated_count?: number; }
export interface MailpitMessage { ID: string; To: { Address: string }[]; HTML?: string; }
export interface SystemInfo { laravel_build_time: string; php_version: string; laravel_version: string; db_version?: string; }
export interface BreadcrumbItem { name: string; type?: string; full_path?: string; }
