import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';
import { t } from '@lingui/core/macro';
import { z } from 'zod';

/**
 * The pricing modes the backend accepts (see `PricingStrategyResolver::MODES`).
 * The empty string is the form value for "no brand overlay".
 */
export const PRICING_STRATEGY_MODES = ['scope_licensing', 'volume_licensing'] as const;

/** One of the known pricing modes. */
export type PricingStrategyMode = typeof PRICING_STRATEGY_MODES[number];

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
    /** The resolved pricing mode of the brand (never null in `effective`). */
    pricing_strategy: PricingStrategyMode | null;
}

/** Where the effective pricing mode comes from. */
export type BrandPricingStrategySource = 'overlay' | 'setting' | 'default';

export interface BrandSetting {
    id: string;
    editable_fields: string[];
    defaults: BrandSettingFields;
    overrides: Record<string, unknown>;
    effective: BrandSettingFields;
    pricing_strategy_source: BrandPricingStrategySource;
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
    /** `null` drops the brand overlay; a mode string sets it. */
    pricing_strategy?: PricingStrategyMode | null;
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
        pricing_strategy: z.string().refine(
            (value) => value === '' || PRICING_STRATEGY_MODES.includes(value as typeof PRICING_STRATEGY_MODES[number]),
            t`Ungültige Preisstrategie.`,
        ),
    });

/**
 * Narrow a form value to a known pricing mode.
 *
 * The zod schema is the gate: it only admits the known modes, so an unknown
 * value never reaches the payload. The empty selection ("no brand overlay")
 * and an unknown value both narrow to `null` — the backend drops the overlay
 * row and the settings row governs again.
 */
export function toPricingStrategyMode(value: string): PricingStrategyMode | null {
    return PRICING_STRATEGY_MODES.find((mode) => mode === value) ?? null;
}

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
