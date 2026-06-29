import {getBrandFromHostname, isAtrBrand, type Brand} from './brandRegistry';

// Re-export the type and primitives so existing consumers keep working through a single import.
export type {Brand} from './brandRegistry';
export {BRAND_B2B, BRAND_ATR, getBrandFromHostname, isAtrBrand, brandPrefix} from './brandRegistry';

export function useBrand() {
    const brand: Brand = getBrandFromHostname(window.location.hostname);
    const isAtr = isAtrBrand(brand);
    return {
        brand,
        isAtr,
        logoSrc: isAtr ? '/brands/atr/android-chrome-192x192.png' : '/brands/rp/android-chrome-192x192.png',
        svgUrl: isAtr ? '/brands/atr/safari-pinned-tab.svg' : '/brands/rp/safari-pinned-tab.svg',
        portalName: isAtr ? 'all-the.rest Portal' : 'Reisinger Foto Portal',
        impressumUrl: isAtr ? 'https://all-the.rest/impressum/' : 'https://reisinger.pictures/impressum/'
    };
}

export function applyTheme() {
    const brand = getBrandFromHostname(window.location.hostname);
    const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    const setTheme = (dark: boolean) => {
        const theme = isAtrBrand(brand)
            ? (dark ? 'atr-dark' : 'atr-light')
            : (dark ? 'b2b-dark' : 'reisinger-light');
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-brand', brand);
    };

    setTheme(isDark);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => setTheme(e.matches));
}
