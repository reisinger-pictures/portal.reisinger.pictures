import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useRef } from 'react';
import { useSWRConfig } from 'swr';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
    useBrandSettings,
    createBrandSettingsSchema,
    toPricingStrategyMode,
    type BrandSetting,
    type BrandSettingsFormValues,
    type BrandSettingsPayload,
} from '../../../logic/useBrandSettings';
import { usePermissions } from '../../../logic/usePermissions';
import { useUI } from '../../components/UIContext';

/**
 * The persisted brand overlay for the pricing strategy — NOT the effective
 * mode. The form carries the override (`''` = none), because the effective
 * mode may come from the settings row: hydrating the form with it would
 * create an overlay for the mode that is merely in force.
 */
function overlayPricingStrategy(brand: BrandSetting): string {
    const value = brand.overrides.pricing_strategy;

    return typeof value === 'string' ? value : '';
}

/**
 * The form values of one brand: the effective values for every plain field,
 * the persisted overlay for the pricing strategy.
 */
function brandToFormValues(brand: BrandSetting): BrandSettingsFormValues {
    const effective = brand.effective;

    return {
        name: effective.name ?? '',
        portal_name: effective.portal_name ?? '',
        from_name: effective.from_name ?? '',
        from_address: effective.from_address ?? '',
        accounting_email: effective.accounting_email ?? '',
        impressum_url: effective.impressum_url ?? '',
        frontend_url: effective.frontend_url ?? '',
        primary_color: effective.primary_color ?? '#1E5631',
        secondary_color: effective.secondary_color ?? '#A4B494',
        features: { orgs: effective.features?.orgs ?? false },
        pricing_strategy: overlayPricingStrategy(brand),
    };
}

/**
 * Per-brand editor. RHF + zod with an explicit "Speichern" button (no per-keystroke
 * PUT). Only dirty fields are sent on save (the backend accepts a partial payload and
 * the default `from_name` is not an e-mail, so sending the full form would trip the
 * server-side e-mail validation). "Auf Standard zurücksetzen" sends `null` for every
 * field to drop all overrides.
 */
function BrandSettingsForm({ brand }: { brand: BrandSetting }) {
    "use no memo";
    const { updateBrandSettings } = useBrandSettings();
    const { mutate: revalidateBrandConfig } = useSWRConfig();
    const { showToast } = useUI();
    const { isSuperAdmin } = usePermissions();
    const canEdit = isSuperAdmin;

    const schema = createBrandSettingsSchema();
    const accountingEmailId = `brand-settings-${brand.id}-accounting-email`;
    const pricingStrategyId = `brand-settings-${brand.id}-pricing-strategy`;

    // The overlay value drives the form; the effective mode is only shown as a
    // hint, so the admin sees what is in force without it becoming editable.
    const effectiveStrategy = brand.effective.pricing_strategy;
    const strategySource = brand.pricing_strategy_source;

    const strategyModeLabel = effectiveStrategy === 'volume_licensing'
        ? t`Mengen-Lizenzierung`
        : t`Scope-Lizenzierung`;
    const strategySourceLabel = strategySource === 'overlay'
        ? t`Brand-Overlay`
        : strategySource === 'setting'
            ? t`DB-Einstellung`
            : t`Standard`;

    const {
        register,
        handleSubmit,
        reset,
        formState: { isSubmitting, errors, isDirty, dirtyFields },
    } = useForm<BrandSettingsFormValues>({
        resolver: zodResolver(schema),
        defaultValues: brandToFormValues(brand),
    });

    // The brand snapshot the form was hydrated from. The hydration below only
    // rewrites the form when a *new* snapshot arrives — a local reset ("Auf
    // Standard zurücksetzen") writes the form itself, and the still-stale prop
    // must not undo it.
    const hydratedFrom = useRef(brand);

    // Hydrate the form once the remote data arrives (load → reset, not derived state).
    useEffect(() => {
        if (isDirty || !brand.effective) {
            // Never overwrite what the admin is editing.
            return;
        }

        if (hydratedFrom.current === brand) {
            // Nothing new to hydrate.
            return;
        }

        hydratedFrom.current = brand;
        reset(brandToFormValues(brand));
    }, [brand, isDirty, reset]);

    const onSubmit = async (data: BrandSettingsFormValues) => {
        const payload: BrandSettingsPayload = {};
        if (dirtyFields.name) payload.name = data.name;
        if (dirtyFields.portal_name) payload.portal_name = data.portal_name;
        if (dirtyFields.from_name) payload.from_name = data.from_name;
        if (dirtyFields.from_address) payload.from_address = data.from_address || null;
        if (dirtyFields.accounting_email) payload.accounting_email = data.accounting_email || null;
        if (dirtyFields.impressum_url) payload.impressum_url = data.impressum_url || null;
        if (dirtyFields.frontend_url) payload.frontend_url = data.frontend_url || null;
        if (dirtyFields.primary_color) payload.primary_color = data.primary_color;
        if (dirtyFields.secondary_color) payload.secondary_color = data.secondary_color;
        if (dirtyFields.features?.orgs) payload.features = { orgs: data.features.orgs };
        if (dirtyFields.pricing_strategy) {
            // An empty selection means "no brand overlay" — the backend drops
            // the override row and the settings row governs again.
            payload.pricing_strategy = toPricingStrategyMode(data.pricing_strategy);
        }

        try {
            await updateBrandSettings(brand.id, payload);
            // The public config is consumed by every branded surface. Revalidate
            // it after the management write so CSS variables update immediately.
            try {
                await revalidateBrandConfig('/api/settings/brand-config');
            } catch {
                // The write succeeded; a later mount/reload can retry this cache.
            }
            showToast('success', t`Markeneinstellungen gespeichert.`);
        } catch {
            showToast('error', t`Fehler beim Speichern der Markeneinstellungen.`);
        }
    };

    const onReset = async () => {
        const payload: BrandSettingsPayload = {
            name: null,
            portal_name: null,
            from_name: null,
            from_address: null,
            accounting_email: null,
            impressum_url: null,
            frontend_url: null,
            primary_color: null,
            secondary_color: null,
            features: { orgs: null },
            pricing_strategy: null,
        };
        try {
            await updateBrandSettings(brand.id, payload);
            try {
                await revalidateBrandConfig('/api/settings/brand-config');
            } catch {
                // The reset succeeded; the public cache can retry on the next load.
            }
            // Hydrate the form from the reset state and clear the dirty state.
            // Without it the select would keep showing the deleted overlay while
            // the hint reports the mode that is now in force, and a later save of
            // another field would re-send `pricing_strategy` and re-create the
            // overlay row. The `brand` prop is still the pre-reset snapshot at
            // this moment, so the pricing strategy is cleared explicitly.
            reset({ ...brandToFormValues(brand), pricing_strategy: '' });
            showToast('success', t`Markeneinstellungen auf Standard zurückgesetzt.`);
        } catch {
            showToast('error', t`Fehler beim Zurücksetzen der Markeneinstellungen.`);
        }
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="pt-2">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold"><Trans>Markenname</Trans></span></label>
                    <input type="text" className="input input-bordered" placeholder={t`Reisinger Pictures`}
                           disabled={!canEdit} required {...register('name')} />
                    {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold"><Trans>Portal-Name</Trans></span></label>
                    <input type="text" className="input input-bordered" placeholder={t`Reisinger Foto Portal`}
                           disabled={!canEdit} required {...register('portal_name')} />
                    {errors.portal_name &&
                        <span className="text-error text-xs mt-1">{errors.portal_name.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold"><Trans>Absender-Name</Trans></span></label>
                    <input type="text" className="input input-bordered" placeholder={t`Reisinger Foto Portal`}
                           disabled={!canEdit} required {...register('from_name')} />
                    {errors.from_name && <span className="text-error text-xs mt-1">{errors.from_name.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Absender-E-Mail</span></label>
                    <input type="email" className="input input-bordered" placeholder="portal@reisinger.pictures"
                           disabled={!canEdit} {...register('from_address')} />
                    {errors.from_address &&
                        <span className="text-error text-xs mt-1">{errors.from_address.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label" htmlFor={accountingEmailId}><span className="label-text font-bold">Buchhaltungs-E-Mail</span></label>
                    <input id={accountingEmailId} type="email" className="input input-bordered" placeholder="buchhaltung@reisinger.pictures"
                           disabled={!canEdit} {...register('accounting_email')} />
                    {errors.accounting_email &&
                        <span className="text-error text-xs mt-1">{errors.accounting_email.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold"><Trans>Impressum-URL</Trans></span></label>
                    <input type="url" className="input input-bordered" placeholder="https://reisinger.pictures/impressum/"
                           disabled={!canEdit} {...register('impressum_url')} />
                    {errors.impressum_url &&
                        <span className="text-error text-xs mt-1">{errors.impressum_url.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold"><Trans>Frontend-URL</Trans></span></label>
                    <input type="url" className="input input-bordered" placeholder="https://portal.reisinger.pictures"
                           disabled={!canEdit} {...register('frontend_url')} />
                    {errors.frontend_url &&
                        <span className="text-error text-xs mt-1">{errors.frontend_url.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold"><Trans>Primärfarbe (Hex)</Trans></span></label>
                    <input type="text" className="input input-bordered font-mono" placeholder="#1E5631"
                           aria-label={t`Primärfarbe (Hex)`} required
                           disabled={!canEdit} {...register('primary_color')} />
                    {errors.primary_color &&
                        <span className="text-error text-xs mt-1">{errors.primary_color.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold"><Trans>Sekundärfarbe (Hex)</Trans></span></label>
                    <input type="text" className="input input-bordered font-mono" placeholder="#A4B494"
                           aria-label={t`Sekundärfarbe (Hex)`} required
                           disabled={!canEdit} {...register('secondary_color')} />
                    {errors.secondary_color &&
                        <span className="text-error text-xs mt-1">{errors.secondary_color.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" className="toggle toggle-primary" disabled={!canEdit}
                               {...register('features.orgs')} />
                        <span className="label-text font-bold"><Trans>Organisationen-Feature aktivieren</Trans></span>
                    </label>
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label" htmlFor={pricingStrategyId}>
                        <span className="label-text font-bold"><Trans>Preisstrategie</Trans></span>
                    </label>
                    <select id={pricingStrategyId} className="select select-bordered" disabled={!canEdit}
                            {...register('pricing_strategy')}>
                        <option value=""><Trans>Standard (DB-Einstellung)</Trans></option>
                        <option value="scope_licensing"><Trans>Scope-Lizenzierung</Trans></option>
                        <option value="volume_licensing"><Trans>Mengen-Lizenzierung</Trans></option>
                    </select>
                    <span className="text-xs opacity-70 mt-1">
                        <Trans>Aktiv: {strategyModeLabel} ({strategySourceLabel})</Trans>
                    </span>
                    {errors.pricing_strategy &&
                        <span className="text-error text-xs mt-1">{errors.pricing_strategy.message}</span>}
                </div>
            </div>

            <div className="mt-6 border-t border-base-300 pt-6 flex items-center gap-4">
                <button type="submit" disabled={isSubmitting || !canEdit} className="btn btn-primary px-8">
                    {isSubmitting && <span className="loading loading-spinner loading-sm"></span>}
                    <Trans>Speichern</Trans>
                </button>
                <button type="button" disabled={isSubmitting || !canEdit} className="btn btn-ghost" onClick={onReset}>
                    <Trans>Auf Standard zurücksetzen</Trans>
                </button>
                {!canEdit && (
                    <span className="text-sm opacity-60"><Trans>Nur Super-Admins können diese Einstellungen bearbeiten.</Trans></span>
                )}
            </div>
        </form>
    );
}

/**
 * F3 (Step 4): Admin-UI for per-brand config overrides. Only rendered for
 * Super-Admins (the PUT endpoint requires the `super_admin` middleware).
 */
export default function BrandSettingsCard() {
    "use no memo";
    const { brands, isLoading, error } = useBrandSettings();
    const { isSuperAdmin } = usePermissions();

    if (!isSuperAdmin) return null;

    return (
        <div className="card bg-base-200 border border-base-300" data-testid="brand-settings-card">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--palette text-primary text-3xl"></span> <Trans>Markeneinstellungen</Trans>
                </h2>
                <p className="text-sm opacity-70 mb-6">
                    <Trans>Überschreibe die konfigurierbaren Markeneinstellungen pro Marke. Leere oder zurückgesetzte Felder fallen auf den Konfigurations-Standard zurück.</Trans>
                </p>

                {isLoading && <span className="loading loading-spinner loading-md"></span>}
                {error && (
                    <div className="alert alert-error shadow-sm">
                        <span><Trans>Markeneinstellungen konnten nicht geladen werden.</Trans></span>
                    </div>
                )}

                {brands?.map((brand) => (
                    <details key={brand.id} className="collapse bg-base-100 border border-base-300">
                        <summary className="collapse-title font-medium">
                            {brand.effective.name} <span className="opacity-60">({brand.id})</span>
                        </summary>
                        <div className="collapse-content">
                            <BrandSettingsForm brand={brand} />
                        </div>
                    </details>
                ))}
            </div>
        </div>
    );
}
