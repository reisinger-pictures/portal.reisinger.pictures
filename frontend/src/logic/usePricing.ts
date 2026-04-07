export type ResolutionTier = 'web' | 'print' | 'original';
export type UsageTier = 'editorial' | 'commercial';
export type DurationTier = '1_year' | 'unlimited';
export type FrequencyTier = 'einmalig' | 'mehrmalig';

const RES_RANKS: Record<string, number> = { 'none': 0, 'web': 1, 'print': 2, 'original': 3 };
const RES_MULT: Record<string, number> = { 'web': 1.0, 'print': 2.0, 'original': 4.0 };
const USE_MULT: Record<string, number> = { 'editorial': 1.0, 'commercial': 3.0 };
const DUR_MULT: Record<string, number> = { '1_year': 1.0, 'unlimited': 2.0 };
const FREQ_MULT: Record<string, number> = { 'einmalig': 1.0, 'mehrmalig': 2.5 };

export function usePricing(basePrice = 15.00) {
    const isCovered = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier, reqFreq: FrequencyTier = 'einmalig') => {
        // Eine Flatrate deckt standardmäßig nur redaktionelle, einmalige Nutzung für 1 Jahr ab!
        if (reqUsage !== 'editorial' || reqDuration !== '1_year' || reqFreq !== 'einmalig') return false;
        return (RES_RANKS[userLevel || 'none'] || 0) >= RES_RANKS[reqRes];
    };

    const calculateUpgradePrice = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier, reqFreq: FrequencyTier = 'einmalig') => {
        if (isCovered(userLevel, reqRes, reqUsage, reqDuration, reqFreq)) return 0;
        
        const requestedPrice = basePrice * RES_MULT[reqRes] * USE_MULT[reqUsage] * DUR_MULT[reqDuration] * FREQ_MULT[reqFreq];
        const userPrice = (userLevel && userLevel !== 'none') ? basePrice * (RES_MULT[userLevel] || 0) * 1.0 * 1.0 : 0;
        
        const delta = requestedPrice - userPrice;
        return delta > 0 ? delta : 0;
    };

    return { isCovered, calculateUpgradePrice };
}
