// Pure Shooting-Calculator-Logik (aus ShootingCalculatorModal.tsx extrahiert, verhaltensgleich).
// Psychologische Rundung ist GEWÜNSCHTES Verhalten — siehe features/ecommerce/07-psychological-pricing.md.

export type ShootingDiscount = '0' | '33' | '50';

export interface ShootingPriceInput {
    calc_base_price?: string;
    calc_hourly_rate?: string;
    calc_images_per_hour?: string;
    duration: number; // Minuten
    images: number;
    flatrate: boolean;
    discount: ShootingDiscount;
}

export interface ShootingPriceResult {
    packagePrice: number; // Listenpreis (psychologisch gerundet)
    finalPrice: number; // Endpreis nach Rabatt (psychologisch gerundet)
    discountAbsolute: number; // packagePrice − finalPrice
}

/**
 * Berechnet den "psychologischen" Preis (verhaltensgleich zur ursprünglichen Inline-Logik).
 */
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

export function calculateShootingPrice(input: ShootingPriceInput): ShootingPriceResult {
    const basePrice = parseFloat(input.calc_base_price || '50');
    const hourlyRate = parseFloat(input.calc_hourly_rate || '100');
    const imagesPerHourPackage = parseInt(input.calc_images_per_hour || '6', 10);
    const durationHours = input.duration / 60;
    const timePrice = durationHours * hourlyRate;
    const imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images;

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
