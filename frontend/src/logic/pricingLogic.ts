// Pure Pricing-Logik. isCovered und calculateUpgradePrice werden direkt aus pricingLogic bezogen.

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

// R-05: Wie getRequiredTerm, aber für dezimale Multiplikatoren (mult_*). Der Backend validiert
// diese als `numeric|min:1` (SettingsController::updateLicenseTerms), Default-Werte sind dezimal
// ('2.0'/'1.5'/'1.5'). parseInt würde '1.5' zu 1 truncieren → stille Preisverfälschung, daher
// parseFloat. Fallback bei nicht geladenen terms: 1 (neutraler Multiplikator — verhindert 0-Preis).
export function getRequiredMultiplier(terms: PricingTerms, key: string): number {
    if (!terms) return 1; // SWR noch nicht geladen — neutraler Multiplikator
    const val = parseFloat(terms[key] || '');
    if (isNaN(val)) {
        throw new Error(`Kritischer Systemfehler: Preisfaktor '${key}' fehlt in der Datenbank. Bitte Administrator kontaktieren!`);
    }
    return val;
}

export function isCovered(
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
    if (isCovered(userLevel, reqRes, reqUsage, reqDuration, reqTerritory)) return 0;

    const priceWeb = getRequiredTerm(terms, 'price_web');
    const pricePrint = getRequiredTerm(terms, 'price_print');
    const priceOriginal = getRequiredTerm(terms, 'price_original');
    const prices: Record<string, number> = {web: priceWeb, print: pricePrint, original: priceOriginal};

    const multCommercial = getRequiredMultiplier(terms, 'mult_commercial');
    const multUnlimited = getRequiredMultiplier(terms, 'mult_unlimited');
    const multInternational = getRequiredMultiplier(terms, 'mult_international');

    const useMult = reqUsage === 'commercial' ? multCommercial : 1.0;
    const durMult = reqDuration === 'unlimited' ? multUnlimited : 1.0;
    const terrMult = reqTerritory === 'international' ? multInternational : 1.0;

    const requestedPrice = prices[reqRes] * useMult * durMult * terrMult;
    const userPrice = (userLevel && userLevel !== 'none') ? (prices[userLevel] || 0) : 0;

    const delta = requestedPrice - userPrice;
    return delta > 0 ? delta : 0;
}
