import { apiDownload } from '../api';

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
