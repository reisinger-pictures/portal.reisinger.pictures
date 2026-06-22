// Pure Pricing-Logik (aus usePricing.ts extrahiert, verhaltensgleich).
// Der Hook usePricing reicht `terms` an diese Funktionen durch.

export type ResolutionTier = 'web' | 'print' | 'original';
export type UsageTier = 'editorial' | 'commercial';
export type DurationTier = '1_year' | 'unlimited';
export type TerritoryTier = 'national' | 'international';

export type PricingTerms = Record<string, string | undefined> | undefined | null;

export const RES_RANKS: Record<string, number> = {none: 0, web: 1, print: 2, original: 3};

export function getRequiredTerm(terms: PricingTerms, key: string): number {
    if (!terms) return 0; // SWR noch nicht geladen
    const val = parseInt(terms[key] || '', 10);
    if (isNaN(val)) {
        throw new Error(`Kritischer Systemfehler: Preisfaktor '${key}' fehlt in der Datenbank. Bitte Administrator kontaktieren!`);
    }
    return val;
}

export function isCovered(
    // `terms` wird von isCovered selbst nicht gebraucht (nur Rang-Vergleich); der Parameter bleibt
    // aus Signatur-Symmetrie zu calculateUpgradePrice erhalten (Hook reicht `terms` einheitlich durch).
    _terms: PricingTerms,
    userLevel: string | undefined,
    reqRes: ResolutionTier,
    reqUsage: UsageTier,
    reqDuration: DurationTier,
    reqTerritory: TerritoryTier = 'national',
): boolean {
    if (reqUsage !== 'editorial' || reqDuration !== '1_year' || reqTerritory !== 'national') return false;
    return (RES_RANKS[userLevel || 'none'] || 0) >= RES_RANKS[reqRes];
}

export function calculateUpgradePrice(
    terms: PricingTerms,
    userLevel: string | undefined,
    reqRes: ResolutionTier,
    reqUsage: UsageTier,
    reqDuration: DurationTier,
    reqTerritory: TerritoryTier = 'national',
): number {
    if (isCovered(terms, userLevel, reqRes, reqUsage, reqDuration, reqTerritory)) return 0;

    const priceWeb = getRequiredTerm(terms, 'price_web');
    const pricePrint = getRequiredTerm(terms, 'price_print');
    const priceOriginal = getRequiredTerm(terms, 'price_original');
    const prices: Record<string, number> = {web: priceWeb, print: pricePrint, original: priceOriginal};

    const multCommercial = getRequiredTerm(terms, 'mult_commercial');
    const multUnlimited = getRequiredTerm(terms, 'mult_unlimited');
    const multInternational = getRequiredTerm(terms, 'mult_international');

    const useMult = reqUsage === 'commercial' ? multCommercial : 1.0;
    const durMult = reqDuration === 'unlimited' ? multUnlimited : 1.0;
    const terrMult = reqTerritory === 'international' ? multInternational : 1.0;

    const requestedPrice = prices[reqRes] * useMult * durMult * terrMult;
    const userPrice = (userLevel && userLevel !== 'none') ? (prices[userLevel] || 0) : 0;

    const delta = requestedPrice - userPrice;
    return delta > 0 ? delta : 0;
}
