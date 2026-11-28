import type { EaAsset } from './useEa';

/**
 * D-93 / EUE-051: the GWG hint is a depreciation-method hint, valid only in
 * the acquisition year and only for a business asset below the limit. Private
 * assets are always depreciated linearly, so they never get the hint.
 *
 * All money values are whole cents; the limit comes from the reference API.
 */
export function gwgEligible(asset: EaAsset, year: number, limitCents: number): boolean {
    if (asset.scope === 'private' || asset.is_private_investment === true) {
        return false;
    }

    if (Number(asset.purchase_date.slice(0, 4)) !== year) {
        return false;
    }

    return asset.cost_cents < limitCents;
}
