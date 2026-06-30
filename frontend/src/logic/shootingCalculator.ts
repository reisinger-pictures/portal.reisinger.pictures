// Pure Shooting-Calculator-Logik (aus ShootingCalculatorModal.tsx extrahiert, verhaltensgleich).
// Psychologische Rundung ist GEWÜNSCHTES Verhalten — siehe features/ecommerce/07-psychological-pricing.md.

export type ShootingDiscount = '0' | '33' | '50';

export interface ShootingPriceInput {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;
    calc_outdoor_multiplier?: string;
    duration: number; // Minuten
    images: number;
    isOutdoor: boolean;
    flatrate: boolean;
    discount: ShootingDiscount;
}

export interface ShootingPriceResult {
    packagePrice: number; // Listenpreis (psychologisch gerundet)
    finalPrice: number; // Endpreis nach Rabatt (psychologisch gerundet)
    discountAbsolute: number; // packagePrice − finalPrice
}

export function roundToPsychologicalValue(value: number): number {
    if (value < 12) {
        return Math.max(1, Math.round(value));
    }
    let rounded;
    if (value >= 1000) {
        rounded = Math.round(value / 50) * 50;
    } else {
        rounded = Math.round(value / 5) * 5;
    }
    if (rounded !== 0 && (rounded % 10 === 0 || (value >= 1000 && rounded % 50 === 0))) {
        rounded -= 1;
    }
    return rounded;
}

export function calculateShootingPrice(input: ShootingPriceInput): ShootingPriceResult { return calculateCustomStudioPrice(input); }

export function calculateCustomStudioPrice(input: ShootingPriceInput): ShootingPriceResult {
    const basePrice = parseFloat(input.calc_base_price || '50');
    const hourlyRate = parseFloat(input.calc_hourly_rate || '80');
    
    const DEFAULT_IMAGES_PER_HOUR = 6;
    const parsedImagesPerHour = parseInt(input.calc_images_per_hour || String(DEFAULT_IMAGES_PER_HOUR), 10);
    const imagesPerHourPackage =
        Number.isFinite(parsedImagesPerHour) && parsedImagesPerHour >= 1
            ? parsedImagesPerHour
            : DEFAULT_IMAGES_PER_HOUR;
            
    const durationHours = input.duration / 60;
    const timePrice = durationHours * hourlyRate;
    
    // Basis-Bildpreis ermitteln
    let imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images;
    
    // Faktor dynamisch aus den DB-Settings parsen (Default: 0.5)
    if (input.isOutdoor) {
        const outdoorMultiplier = parseFloat(input.calc_outdoor_multiplier || '0.5');
        imagesPrice = imagesPrice * outdoorMultiplier;
    }

    const multiplier = input.flatrate ? 1.2 : 1;
    const rawTotal = (basePrice + timePrice + imagesPrice) * multiplier;
    const packagePrice = roundToPsychologicalValue(rawTotal);

    let currentDiscountPercent = 0;
    if (input.discount === '33') currentDiscountPercent = 100 / 3;
    else if (input.discount === '50') currentDiscountPercent = 50;

    const rawFinalPrice = packagePrice - (packagePrice * (currentDiscountPercent / 100));
    const finalPrice = input.discount !== '0' ? roundToPsychologicalValue(rawFinalPrice) : packagePrice;
    const discountAbsolute = packagePrice - finalPrice;

    return {packagePrice, finalPrice, discountAbsolute};
}


export interface B2CFlexInput {
    type: 'portrait' | 'couple' | 'nude';
    setup: 'outdoor' | 'outdoor_flash' | 'indoor';
    extraImages: number;
    isFullyPrivate: boolean;
    // NEU: Dynamische Parameter von der API
    srp_base_price?: string;
    srp_setup_fee?: string;
    srp_privacy_fee?: string;
    srp_extra_image_fee?: string;
}

export function calculateB2CFlexPrice(input: B2CFlexInput): ShootingPriceResult {
    // NEU: Fallback-Werte greifen nur, wenn die DB leer sein sollte (Unterstützung für Cent/Dezimal-Beträge)
    const basePrice = parseFloat(input.srp_base_price || '149');
    const setupCost = parseFloat(input.srp_setup_fee || '50');
    const extraImageCost = parseFloat(input.srp_extra_image_fee || '15');
    const privacyBase = parseFloat(input.srp_privacy_fee || '200');

    let setupFee = 0;
    if (input.setup === 'outdoor_flash' || input.setup === 'indoor') {
        setupFee = setupCost;
    }

    const extraImagesFee = input.extraImages * extraImageCost;
    let privacyFee = 0;

    if (input.type === 'nude' && input.isFullyPrivate) {
        // Formel: Basis-Aufpreis + (Zusatzbilder * reduzierter Bildpreis-Faktor)
        privacyFee = privacyBase + (input.extraImages * Math.round(extraImageCost / 3));
    }

    const total = basePrice + setupFee + extraImagesFee + privacyFee;

    return {
        packagePrice: total,
        finalPrice: total,
        discountAbsolute: 0
    };
}
