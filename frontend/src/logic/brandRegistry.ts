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
}

const themeMap: Record<string, { light: string; dark: string }> = {
    rp: {light: 'reisinger-light', dark: 'b2b-dark'},
    srp: {light: 'srp-light', dark: 'srp-dark'},
};

const defaultTheme = {light: 'reisinger-light', dark: 'b2b-dark'};

export function getBrandFromHostname(hostname: string): BrandId {
    const h = hostname.toLowerCase();
    if (h.startsWith('buy.') || h === 'srp.localhost' || h.endsWith('.srp.localhost')) {
        return 'srp';
    }
    return 'rp';
}

export function getBrandTheme(brand: BrandId): { light: string; dark: string } {
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
    const theme = getBrandTheme(brand);

    return {
        brand,
        config,
        logoSrc: config?.logo_path ?? `/brands/${brand}/android-chrome-192x192.png`,
        svgUrl: `/brands/${brand}/safari-pinned-tab.svg`,
        portalName: config?.portal_name ?? 'Reisinger Foto Portal',
        impressumUrl: config?.impressum_url ?? null,
        features: config?.features ?? {},
        theme,
    };
}

export function applyTheme() {
    const brand = getBrandFromHostname(window.location.hostname);
    const theme = getBrandTheme(brand);
    const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    const setTheme = (dark: boolean) => {
        document.documentElement.setAttribute('data-theme', dark ? theme.dark : theme.light);
        document.documentElement.setAttribute('data-brand', brand);
    };

    setTheme(isDark);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => setTheme(e.matches));
}
