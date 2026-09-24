import { useEffect } from 'react';
import useSWR from 'swr';
import {fetcher} from '../api';

export type BrandId = string;

export interface BrandFeatures {
    coupons?: boolean;
    orgs?: boolean;
    volume_licensing?: boolean;
    [key: string]: boolean | undefined;
}

export interface BrandConfig {
    id: BrandId;
    name: string;
    theme: string;
    portal_name: string;
    impressum_url: string | null;
    logo_path: string | null;
    features: BrandFeatures;
    primary_color: string;
    secondary_color: string;
}

const themeMap: Record<string, { light: string; dark: string }> = {
    rp: {light: 'rp-light', dark: 'rp-dark'},
};

const defaultTheme = {light: 'rp-light', dark: 'rp-dark'};
const defaultPrimaryColor = '#1E5631';
const defaultSecondaryColor = '#A4B494';
const hexColorPattern = /^#[0-9a-fA-F]{6}$/;

type BrandColorConfig = Partial<Pick<BrandConfig, 'primary_color' | 'secondary_color'>>;

function isHexColor(value: unknown): value is string {
    return typeof value === 'string' && hexColorPattern.test(value);
}

function setColorVariable(property: '--color-primary' | '--color-secondary', value: unknown) {
    if (typeof document === 'undefined') return;

    if (isHexColor(value)) {
        document.documentElement.style.setProperty(property, value);
    } else {
        // Invalid/missing remote values must never become part of a CSS declaration.
        // Removing the property lets the selected daisyUI theme provide its default.
        document.documentElement.style.removeProperty(property);
    }
}

/**
 * Apply only validated six-digit hex colors. Brand settings are remote data,
 * so the values are deliberately checked before touching the document.
 */
export function applyBrandColors(config?: BrandColorConfig | null) {
    setColorVariable('--color-primary', config?.primary_color);
    setColorVariable('--color-secondary', config?.secondary_color);
}

export function getBrandFromHostname(hostname: string): BrandId {
    const h = hostname.toLowerCase();
    // Dev fallback: *.localhost → brand from subdomain
    if (h.endsWith('.localhost') || h === 'localhost') {
        const parts = h.split('.');
        if (parts.length >= 2 && parts[0] !== 'localhost') {
            return parts[0];
        }
    }
    return 'rp';
}

export function getBrandTheme(brand: BrandId, config?: BrandConfig | null): { light: string; dark: string } {
    if (config?.theme) {
        return {light: `${config.theme}-light`, dark: `${config.theme}-dark`};
    }
    return themeMap[brand] ?? defaultTheme;
}

export function useBrandConfig() {
    const {data, error, isLoading} = useSWR<BrandConfig>('/api/settings/brand-config', fetcher, {
        dedupingInterval: 300_000,
    });

    return {
        config: data ?? null,
        isLoading,
        error,
    };
}

export function useBrand() {
    const brand = getBrandFromHostname(window.location.hostname);
    const {config} = useBrandConfig();
    const theme = getBrandTheme(brand, config);
    const primaryColor = isHexColor(config?.primary_color) ? config.primary_color : defaultPrimaryColor;
    const secondaryColor = isHexColor(config?.secondary_color) ? config.secondary_color : defaultSecondaryColor;

    useEffect(() => {
        applyBrandColors({
            primary_color: config?.primary_color,
            secondary_color: config?.secondary_color,
        });
    }, [config?.primary_color, config?.secondary_color]);

    return {
        brand,
        config,
        logoSrc: config?.logo_path ?? `/brands/${brand}/android-chrome-192x192.png`,
        svgUrl: `/brands/${brand}/safari-pinned-tab.svg`,
        portalName: config?.portal_name ?? 'Reisinger Foto Portal',
        impressumUrl: config?.impressum_url ?? null,
        features: config?.features ?? {},
        theme,
        primaryColor,
        secondaryColor,
    };
}

let removeThemeListener: (() => void) | null = null;

export function applyTheme(config?: BrandConfig | null) {
    // Re-apply safe: remove a previous listener before installing a new one.
    removeThemeListener?.();
    removeThemeListener = null;

    const brand = getBrandFromHostname(window.location.hostname);
    const theme = getBrandTheme(brand, config);
    applyBrandColors(config);
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const setTheme = (dark: boolean) => {
        document.documentElement.setAttribute('data-theme', dark ? theme.dark : theme.light);
        document.documentElement.setAttribute('data-brand', brand);
    };

    setTheme(mediaQuery.matches);

    const onMediaChange = (e: MediaQueryListEvent) => setTheme(e.matches);
    mediaQuery.addEventListener('change', onMediaChange);
    removeThemeListener = () => mediaQuery.removeEventListener('change', onMediaChange);
}

// HMR-Safety: beim Ersetzen dieses Moduls den alten matchMedia-Listener abräumen.
if (import.meta.hot) {
    import.meta.hot.dispose(() => {
        removeThemeListener?.();
        removeThemeListener = null;
    });
}
