export type ResolutionTier = 'web' | 'print' | 'original';
export type UsageTier = 'editorial' | 'commercial';
export type DurationTier = '1_year' | 'unlimited';
export type TerritoryTier = 'national' | 'international';

const RES_RANKS: Record<string, number> = { 'none': 0, 'web': 1, 'print': 2, 'original': 3 };

export function usePricing(terms: Record<string, string | undefined> | undefined | null) {
    const getRequiredTerm = (key: string): number => {
        if (!terms) return 0; // Warten, bis SWR geladen hat
        const val = parseInt(terms[key] || '', 10);
        if (isNaN(val)) {
            throw new Error(`Kritischer Systemfehler: Preisfaktor '${key}' fehlt in der Datenbank. Bitte Administrator kontaktieren!`);
        }
        return val;
    };

    const isCovered = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier, reqTerritory: TerritoryTier = 'national') => {
        if (reqUsage !== 'editorial' || reqDuration !== '1_year' || reqTerritory !== 'national') return false;
        return (RES_RANKS[userLevel || 'none'] || 0) >= RES_RANKS[reqRes];
    };

    const calculateUpgradePrice = (userLevel: string | undefined, reqRes: ResolutionTier, reqUsage: UsageTier, reqDuration: DurationTier, reqTerritory: TerritoryTier = 'national') => {
        if (isCovered(userLevel, reqRes, reqUsage, reqDuration, reqTerritory)) return 0;
        
        const priceWeb = getRequiredTerm('price_web');
        const pricePrint = getRequiredTerm('price_print');
        const priceOriginal = getRequiredTerm('price_original');
        const prices: Record<string, number> = { web: priceWeb, print: pricePrint, original: priceOriginal };

        const multCommercial = getRequiredTerm('mult_commercial');
        const multUnlimited = getRequiredTerm('mult_unlimited');
        const multInternational = getRequiredTerm('mult_international');

        const useMult = reqUsage === 'commercial' ? multCommercial : 1.0;
        const durMult = reqDuration === 'unlimited' ? multUnlimited : 1.0;
        const terrMult = reqTerritory === 'international' ? multInternational : 1.0;

        const requestedPrice = prices[reqRes] * useMult * durMult * terrMult;
        const userPrice = (userLevel && userLevel !== 'none') ? (prices[userLevel] || 0) : 0;
        
        const delta = requestedPrice - userPrice;
        return delta > 0 ? delta : 0;
    };

    return { isCovered, calculateUpgradePrice };
}
