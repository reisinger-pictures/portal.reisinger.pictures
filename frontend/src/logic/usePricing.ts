export type ResolutionTier = 'web' | 'print' | 'original';
export type UsageTier = 'editorial' | 'commercial';
export type DurationTier = '1_year' | 'unlimited';

const RES_RANKS: Record<string, number> = { 'none': 0, 'web': 1, 'print': 2, 'original': 3 };
const RES_MULT: Record<string, number> = { 'web': 1.0, 'print': 2.0, 'original': 4.0 };
const USE_MULT: Record<string, number> = { 'editorial': 1.0, 'commercial': 3.0 };
const DUR_MULT: Record<string, number> = { '1_year': 1.0, 'unlimited': 2.0 };

export function usePricing(basePrice = 15.00) {
    const isCovered = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier) => {
        // Eine Flatrate deckt standardmäßig nur redaktionelle Nutzung für 1 Jahr ab!
        if (reqUsage !== 'editorial' || reqDuration !== '1_year') return false;
        return (RES_RANKS[userLevel || 'none'] || 0) >= RES_RANKS[reqRes];
    };

    const calculateUpgradePrice = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier) => {
        if (isCovered(userLevel, reqRes, reqUsage, reqDuration)) return 0;
        
        const requestedPrice = basePrice * RES_MULT[reqRes] * USE_MULT[reqUsage] * DUR_MULT[reqDuration];
        const userPrice = (userLevel && userLevel !== 'none') ? basePrice * (RES_MULT[userLevel] || 0) * 1.0 * 1.0 : 0;
        
        const delta = requestedPrice - userPrice;
        return delta > 0 ? delta : 0;
    };

    return { isCovered, calculateUpgradePrice };
}
