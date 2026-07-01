import { useBrand } from './useBrand';

export type LicensingMode = 'scope_licensing' | 'volume_licensing';

/**
 * Determine the active licensing mode based on the current brand.
 *
 * In the future this will be fetched from the API/settings; currently
 * it is hardcoded by brand.
 */
export function useLicensingMode(): LicensingMode {
  const { brand } = useBrand();
  // TODO: fetch from settings/API instead of hardcoded brand check
  return brand === 'srp' ? 'volume_licensing' : 'scope_licensing';
}
