import useSWR from 'swr';
import {fetcher} from '../api';
import {useLicenseTerms} from './useLicenseTerms';

export type LicensingMode = 'scope_licensing' | 'volume_licensing';

export interface LicensingModeStatus {
    /** Effective mode once resolved; safe default while loading. */
    mode: LicensingMode;
    /**
     * True while the requested gallery's own terms (or the brand terms for a
     * gallery-less caller) are still unresolved. A UI that gates on the mode
     * only must gate on this flag too, otherwise the brand default can be shown
     * in place of a gallery override.
     */
    isLoading: boolean;
}

/**
 * Determine the active licensing mode together with its resolution status from
 * the backend `pricing_strategy` setting (delivered via
 * /api/settings/license-terms).
 *
 * When a `galleryId` is provided, the backend resolves the effective mode
 * considering the gallery's `licensing_mode` override (if any). The brand terms
 * are only used once the gallery's own response has arrived (or definitively
 * failed); while it is still loading, `isLoading` is true and `mode` may be the
 * brand default — callers must not render a gallery-specific map then.
 */
export function useLicensingModeStatus(galleryId?: string): LicensingModeStatus {
    const { terms, isLoading: globalTermsLoading } = useLicenseTerms();
    const galleryKey = galleryId ? `/api/settings/license-terms?gallery_id=${galleryId}` : null;
    const { data: galleryTerms, isLoading: galleryTermsLoading } = useSWR<{ pricing_strategy?: string }>(
        galleryKey,
        fetcher,
        { revalidateOnFocus: false },
    );

    const hasGalleryTerms = galleryTerms !== undefined && galleryTerms !== null;
    const effectiveTerms = galleryId && hasGalleryTerms ? galleryTerms : terms;

    return {
        mode: effectiveTerms?.pricing_strategy === 'volume_licensing'
            ? 'volume_licensing'
            : 'scope_licensing',
        isLoading: galleryId
            ? (!hasGalleryTerms && (galleryTermsLoading || globalTermsLoading))
            : globalTermsLoading,
    };
}

/**
 * Backwards-compatible mode-only view for callers that do not (yet) render a
 * gallery-specific pricing map and therefore do not need the loading flag.
 */
export function useLicensingMode(galleryId?: string): LicensingMode {
    return useLicensingModeStatus(galleryId).mode;
}
