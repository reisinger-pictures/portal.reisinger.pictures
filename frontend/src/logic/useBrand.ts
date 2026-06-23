export type Brand = 'reisinger.pictures' | 'all-the.rest';

export function getBrandFromHostname(hostname: string): Brand {
    if (hostname.includes('all-the.rest')) return 'all-the.rest';
    return 'reisinger.pictures';
}

export function useBrand() {
    const brand = getBrandFromHostname(window.location.hostname);
    const isAtr = brand === 'all-the.rest';
    return {
        brand,
        isAtr,
        logoSrc: isAtr ? '/brands/atr/android-chrome-192x192.png' : '/brands/rp/android-chrome-192x192.png',
        portalName: isAtr ? 'all-the.rest Portal' : 'Reisinger Foto Portal'
    };
}

export function applyTheme() {
    const brand = getBrandFromHostname(window.location.hostname);
    const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    const setTheme = (dark: boolean) => {
        let theme: string;
        if (brand === 'all-the.rest') {
            theme = dark ? 'atr-dark' : 'atr-light';
        } else {
            theme = dark ? 'b2b-dark' : 'reisinger-light';
        }
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-brand', brand);
    };

    setTheme(isDark);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => setTheme(e.matches));
}
