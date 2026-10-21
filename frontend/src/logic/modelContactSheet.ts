import { apiDownload, apiMutate, filenameFromContentDisposition } from '../api';

/** Server-supported contact-sheet variants (`?variant=…`). */
export const MODEL_CONTACT_SHEET_VARIANTS = ['internal', 'external'] as const;
export type ModelContactSheetVariant = (typeof MODEL_CONTACT_SHEET_VARIANTS)[number];

/**
 * Authenticated download URL for a model's contact sheet PDF. The backend is
 * authoritative for the content (`AdminOnly` + brand scope).
 */
export function buildModelContactSheetUrl(modelId: string, variant: ModelContactSheetVariant): string {
    const params = new URLSearchParams({ variant });
    return `/api/management/models/${encodeURIComponent(modelId)}/contact-sheet?${params.toString()}`;
}

/** Fallback filename when the server omits `Content-Disposition`. */
export function fallbackContactSheetFilename(modelId: string, variant: ModelContactSheetVariant): string {
    return `model-${modelId}-${variant}-contact-sheet.pdf`;
}

/**
 * Save an in-memory blob via a synthetic `<a download>` click. The temporary
 * object URL is revoked immediately afterwards so no URL leaks. Side effect by
 * design — only ever called from user-event handlers (no `useEffect`).
 */
export function saveBlobAsFile(blob: Blob, filename: string): void {
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    anchor.rel = 'noopener';
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(objectUrl);
}

/**
 * Fetch a contact sheet through the authenticated API layer and trigger the
 * browser download. Rejects on failure so the caller can surface it (toast) —
 * errors are never swallowed.
 */
export async function downloadModelContactSheet(
    modelId: string,
    variant: ModelContactSheetVariant,
): Promise<void> {
    const { blob, filename } = await apiDownload(buildModelContactSheetUrl(modelId, variant));
    saveBlobAsFile(blob, filename ?? fallbackContactSheetFilename(modelId, variant));
}

/**
 * Maximum number of models in one bulk ("Ergebnisliste") contact sheet.
 *
 * The server is the source of truth (`ModelContactSheetService::MAX_BULK_PROFILES`);
 * this constant only mirrors it so the UI can refuse an over-long selection
 * before a request is sent.
 */
export const MODEL_CONTACT_SHEET_MAX_BULK = 25;

/**
 * Authenticated endpoint for a bulk contact sheet. The selected ids travel in
 * the POST body — the URL itself stays free of ids (and of PII).
 */
export function buildModelContactSheetBulkUrl(): string {
    return '/api/management/models/contact-sheet';
}

/** Fallback filename when the server omits `Content-Disposition`. */
export function fallbackContactSheetBulkFilename(variant: ModelContactSheetVariant): string {
    return `models-contact-sheet-${variant}-contact-sheet.pdf`;
}

/**
 * Fetch a bulk contact sheet for a selection of models and trigger the browser
 * download. Rejects on failure so the caller can surface it (toast) — errors
 * are never swallowed.
 */
export async function downloadModelContactSheetBulk(
    ids: string[],
    variant: ModelContactSheetVariant,
): Promise<void> {
    const { blob, filename } = await apiDownload(buildModelContactSheetBulkUrl(), {
        method: 'POST',
        body: JSON.stringify({ ids, variant }),
        headers: { 'Content-Type': 'application/json' },
    });
    saveBlobAsFile(blob, filename ?? fallbackContactSheetBulkFilename(variant));
}

// ---------------------------------------------------------------------------
// Phase 4 — signierter Extern-Link (Empfänger ohne Login)
// ---------------------------------------------------------------------------

/**
 * Authenticated endpoint that issues a signed, expiring external link.
 *
 * The variant is not part of the request: a share link is **always** the
 * external variant (the internal one carries PII and is never shareable).
 */
export function buildModelContactSheetShareCreateUrl(): string {
    return '/api/management/models/contact-sheet/share';
}

/**
 * Public, login-free endpoint that delivers the shared contact sheet PDF.
 *
 * The token in the path *is* the credential — the endpoint needs no session,
 * which is why the download below deliberately does not use `apiDownload`.
 */
export function buildModelContactSheetShareUrl(token: string): string {
    return `/api/public/model-contact-sheet/${encodeURIComponent(token)}`;
}

/** Fallback filename when the server omits `Content-Disposition`. */
export function fallbackContactSheetShareFilename(): string {
    return 'models-contact-sheet-external-contact-sheet.pdf';
}

export interface ModelContactSheetShare {
    /** Frontend URL of the share page (contains the bearer token). */
    url: string;
    /** ISO-8601 expiry of the link. */
    expires_at: string;
    /** Always `external` — the only shareable variant. */
    variant: string;
    /** Number of profiles the link covers. */
    count: number;
}

/**
 * Create a signed, expiring external link for the given profiles.
 *
 * Rejects on failure so the caller can surface it (toast) — errors are never
 * swallowed.
 */
export async function createModelContactSheetShare(ids: string[]): Promise<ModelContactSheetShare> {
    return apiMutate<ModelContactSheetShare>(buildModelContactSheetShareCreateUrl(), 'POST', { ids });
}

/**
 * Download the shared contact sheet through the **public** endpoint.
 *
 * A plain `fetch` on purpose: the endpoint needs neither credentials nor a
 * 401-refresh path. `credentials: 'omit'` makes that explicit — the token in
 * the path is the only credential, so no ambient session cookie travels with
 * the request (which `apiDownload` would always send). Rejects on failure so
 * the caller can show a message.
 */
export async function downloadModelContactSheetShare(token: string): Promise<void> {
    const response = await fetch(buildModelContactSheetShareUrl(token), { credentials: 'omit' });

    if (!response.ok) {
        throw new Error(`HTTP Fehler ${response.status}`);
    }

    const blob = await response.blob();
    const filename = filenameFromContentDisposition(response.headers.get('content-disposition'))
        ?? fallbackContactSheetShareFilename();

    saveBlobAsFile(blob, filename);
}

/**
 * The expiry the share token carries in its own claims.
 *
 * This is a **display** concern only (the hint on the public page): the token
 * is a bearer credential that the browser already holds in the URL, and the
 * server stays the sole authority on validity. The payload carries no model
 * data — only ids, brand and the expiry.
 */
export function shareLinkExpiry(token: string): Date | null {
    const parts = token.split('.');
    if (parts.length !== 3) return null;

    const decoded = decodeBase64Url(parts[1]);
    if (decoded === null) return null;

    try {
        const claims = JSON.parse(decoded) as { exp?: unknown };
        if (typeof claims.exp !== 'number') return null;

        const expiry = new Date(claims.exp * 1000);
        return Number.isNaN(expiry.getTime()) ? null : expiry;
    } catch {
        return null;
    }
}

function decodeBase64Url(value: string): string | null {
    try {
        const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
        const padded = normalized.padEnd(normalized.length + ((4 - (normalized.length % 4)) % 4), '=');
        return atob(padded);
    } catch {
        return null;
    }
}

/**
 * Copy a share link to the clipboard.
 *
 * Uses the async clipboard API when available and falls back to a hidden
 * textarea (`execCommand`) for browsers without it. Throws when the copy
 * fails so the caller can show an error.
 */
export async function copyTextToClipboard(text: string): Promise<void> {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.className = 'fixed opacity-0 pointer-events-none';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);

    if (!copied) throw new Error('Clipboard copy failed');
}
