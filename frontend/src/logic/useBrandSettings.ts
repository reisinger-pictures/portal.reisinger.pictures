import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';
import { z } from 'zod';

/** Effective/config values for a single brand (nested `features` object). */
export interface BrandSettingFields {
    name: string;
    portal_name: string;
    impressum_url: string | null;
    primary_color: string;
    secondary_color: string;
    frontend_url: string | null;
    from_address: string | null;
    from_name: string | null;
    accounting_email: string | null;
    features: { orgs: boolean };
}

export interface BrandSetting {
    id: string;
    editable_fields: string[];
    defaults: BrandSettingFields;
    overrides: Record<string, unknown>;
    effective: BrandSettingFields;
}

/** Partial PUT payload. `null` resets a single field back to its config default. */
export interface BrandSettingsPayload {
    name?: string | null;
    portal_name?: string | null;
    impressum_url?: string | null;
    primary_color?: string | null;
    secondary_color?: string | null;
    frontend_url?: string | null;
    from_address?: string | null;
    from_name?: string | null;
    accounting_email?: string | null;
    features?: { orgs?: boolean | null } | null;
}

const hexColorRegex = /^#[0-9a-fA-F]{6}$/;

/**
 * Zod schema for a single brand's editable form values. Factory function
 * (called inside the component body) — keeps the Lingui/i18n macro out of
 * module scope so the production bundle never evaluates `t` before locale
 * activation (blank-page regression, see frontend/AGENTS.md).
 */
export const createBrandSettingsSchema = () =>
    z.object({
        name: z.string().min(1, 'Name darf nicht leer sein'),
        portal_name: z.string().min(1, 'Portal-Name darf nicht leer sein'),
        from_name: z.string().min(1, 'Absender-Name darf nicht leer sein'),
        from_address: z.string().email('Ungültige E-Mail-Adresse').or(z.literal('')),
        accounting_email: z.string().email('Ungültige E-Mail-Adresse').or(z.literal('')),
        impressum_url: z.string().url('Ungültige URL').or(z.literal('')),
        frontend_url: z.string().url('Ungültige URL').or(z.literal('')),
        primary_color: z.string().regex(hexColorRegex, 'Hex-Farbwert erforderlich (z. B. #1E5631)'),
        secondary_color: z.string().regex(hexColorRegex, 'Hex-Farbwert erforderlich (z. B. #1E5631)'),
        features: z.object({ orgs: z.boolean() }),
    });

export type BrandSettingsFormValues = z.infer<ReturnType<typeof createBrandSettingsSchema>>;

/**
 * F3 (Step 3): Management endpoint for per-brand config overrides.
 * GET lists every configured brand with its editable whitelist, the config
 * defaults, the current DB overrides and the merged effective values. PUT
 * persists (or resets via `null`) a partial set of overrides.
 */
export function useBrandSettings() {
    const { data, isLoading, error, mutate } = useSWR<{ brands: BrandSetting[] }>(
        '/api/management/brand-settings',
        fetcher,
        { revalidateOnFocus: false }
    );

    const updateBrandSettings = async (brand: string, payload: BrandSettingsPayload) => {
        await apiMutate(`/api/management/brand-settings/${brand}`, 'PUT', payload);
        await mutate();
    };

    return { brands: data?.brands, isLoading, error, updateBrandSettings };
}
