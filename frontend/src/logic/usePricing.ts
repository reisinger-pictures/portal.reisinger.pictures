// Dünner Hook-Wrapper: reicht `terms` an die pure Logik in pricingLogic.ts weiter (verhaltensgleich).
export {RES_RANKS, getRequiredTerm, getRequiredMultiplier, isCovered, calculateUpgradePrice} from './pricingLogic';
export type {PricingTerms, ResolutionTier, UsageTier, DurationTier, TerritoryTier} from './pricingLogic';
import {isCovered, calculateUpgradePrice, PricingTerms, ResolutionTier, UsageTier, DurationTier, TerritoryTier} from './pricingLogic';

export function usePricing(terms: PricingTerms) {
    const isCoveredBound = (
        userLevel: string | undefined,
        reqRes: ResolutionTier,
        reqUsage: UsageTier,
        reqDuration: DurationTier,
        reqTerritory: TerritoryTier = 'national',
    ) => isCovered(userLevel, reqRes, reqUsage, reqDuration, reqTerritory);

    const calculateUpgradePriceBound = (
        userLevel: string | undefined,
        reqRes: ResolutionTier,
        reqUsage: UsageTier,
        reqDuration: DurationTier,
        reqTerritory: TerritoryTier = 'national',
    ) => calculateUpgradePrice(terms, userLevel, reqRes, reqUsage, reqDuration, reqTerritory);

    return {isCovered: isCoveredBound, calculateUpgradePrice: calculateUpgradePriceBound};
}
