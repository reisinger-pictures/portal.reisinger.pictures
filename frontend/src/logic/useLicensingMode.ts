import { useLicenseTerms } from './useLicenseTerms';

export type LicensingMode = 'scope_licensing' | 'volume_licensing';

/**
 * Determine the active licensing mode from the backend `pricing_strategy`
 * setting (delivered via /api/settings/license-terms).
 *
 * Falls back to 'scope_licensing' while the terms are loading or when the
 * setting is absent. This replaces the previous hardcoded brand check and
 * allows volume licensing (and its coupled coupons) to be enabled per-brand
 * via the `pricing_strategy` setting.
 */
export function useLicensingMode(): LicensingMode {
  const { terms } = useLicenseTerms();
  return terms?.pricing_strategy === 'volume_licensing'
    ? 'volume_licensing'
    : 'scope_licensing';
}
