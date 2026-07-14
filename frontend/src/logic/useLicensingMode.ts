import useSWR from 'swr';
import {fetcher} from '../api';
import {useLicenseTerms} from './useLicenseTerms';

export type LicensingMode = 'scope_licensing' | 'volume_licensing';

/**
 * Determine the active licensing mode from the backend `pricing_strategy`
 * setting (delivered via /api/settings/license-terms).
 *
 * Falls back to 'scope_licensing' while the terms are loading or when the
 * setting is absent.
 *
 * When a `galleryId` is provided, the backend resolves the effective mode
 * considering the gallery's `licensing_mode` override (if any).
 */
export function useLicensingMode(galleryId?: string): LicensingMode {
  const { terms } = useLicenseTerms();
  const galleryKey = galleryId ? `/api/settings/license-terms?gallery_id=${galleryId}` : null;
  const { data: galleryTerms } = useSWR<{ pricing_strategy?: string }>(galleryKey, fetcher, {
    revalidateOnFocus: false,
  });

  const effectiveTerms = galleryId && galleryTerms ? galleryTerms : terms;

  return effectiveTerms?.pricing_strategy === 'volume_licensing'
    ? 'volume_licensing'
    : 'scope_licensing';
}
