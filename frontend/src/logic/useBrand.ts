import {getBrandFromHostname, isSrpBrand, type Brand} from './brandRegistry';

// Re-export the type and primitives so existing consumers keep working through a single import.
export type {Brand} from './brandRegistry';
export {BRAND_B2B, BRAND_SRP, getBrandFromHostname, isSrpBrand, brandPrefix} from './brandRegistry';

export function useBrand() {
    const brand: Brand = getBrandFromHostname(window.location.hostname);
    const isSrp = isSrpBrand(brand);
    return {
        brand,
        isSrp,
        logoSrc: isSrp ? '/brands/srp/android-chrome-192x192.png' : '/brands/rp/android-chrome-192x192.png',
        svgUrl: isSrp ? '/brands/srp/safari-pinned-tab.svg' : '/brands/rp/safari-pinned-tab.svg',
        portalName: isSrp ? 'story.reisinger.pictures Portal' : 'Reisinger Foto Portal',
        impressumUrl: isSrp ? 'https://story.reisinger.pictures/impressum/' : 'https://reisinger.pictures/impressum/'
    };
}

export function applyTheme() {
    const brand = getBrandFromHostname(window.location.hostname);
    const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    const setTheme = (dark: boolean) => {
        const theme = isSrpBrand(brand)
            ? (dark ? 'srp-dark' : 'srp-light')
            : (dark ? 'b2b-dark' : 'reisinger-light');
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-brand', brand);
    };

    setTheme(isDark);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => setTheme(e.matches));
}
