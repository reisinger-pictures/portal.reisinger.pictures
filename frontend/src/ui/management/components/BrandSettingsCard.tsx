import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
    useBrandSettings,
    createBrandSettingsSchema,
    type BrandSetting,
    type BrandSettingsFormValues,
    type BrandSettingsPayload,
} from '../../../logic/useBrandSettings';
import { usePermissions } from '../../../logic/usePermissions';
import { useUI } from '../../components/UIContext';

function effectiveToFormValues(effective: BrandSetting['effective']): BrandSettingsFormValues {
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
    const { showToast } = useUI();
    const { isSuperAdmin } = usePermissions();
    const canEdit = isSuperAdmin;

    const schema = createBrandSettingsSchema();

    const {
        register,
        handleSubmit,
        reset,
        formState: { isSubmitting, errors, isDirty, dirtyFields },
    } = useForm<BrandSettingsFormValues>({
        resolver: zodResolver(schema),
        defaultValues: effectiveToFormValues(brand.effective),
    });

    // Hydrate the form once the remote data arrives (load → reset, not derived state).
    useEffect(() => {
        if (!isDirty && brand.effective) {
            reset(effectiveToFormValues(brand.effective));
        }
    }, [brand.effective, isDirty, reset]);

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

        try {
            await updateBrandSettings(brand.id, payload);
            showToast('success', 'Markeneinstellungen gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern der Markeneinstellungen.');
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
        };
        try {
            await updateBrandSettings(brand.id, payload);
            showToast('success', 'Markeneinstellungen auf Standard zurückgesetzt.');
        } catch {
            showToast('error', 'Fehler beim Zurücksetzen der Markeneinstellungen.');
        }
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="pt-2">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">Markenname</span></label>
                    <input type="text" className="input input-bordered" placeholder="Reisinger Pictures"
                           disabled={!canEdit} required {...register('name')} />
                    {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">Portal-Name</span></label>
                    <input type="text" className="input input-bordered" placeholder="Reisinger Foto Portal"
                           disabled={!canEdit} required {...register('portal_name')} />
                    {errors.portal_name &&
                        <span className="text-error text-xs mt-1">{errors.portal_name.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Absender-Name</span></label>
                    <input type="text" className="input input-bordered" placeholder="Reisinger Foto Portal"
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
                    <label className="label"><span className="label-text font-bold">Buchhaltungs-E-Mail</span></label>
                    <input type="email" className="input input-bordered" placeholder="buchhaltung@reisinger.pictures"
                           disabled={!canEdit} {...register('accounting_email')} />
                    {errors.accounting_email &&
                        <span className="text-error text-xs mt-1">{errors.accounting_email.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">Impressum-URL</span></label>
                    <input type="url" className="input input-bordered" placeholder="https://reisinger.pictures/impressum/"
                           disabled={!canEdit} {...register('impressum_url')} />
                    {errors.impressum_url &&
                        <span className="text-error text-xs mt-1">{errors.impressum_url.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">Frontend-URL</span></label>
                    <input type="url" className="input input-bordered" placeholder="https://portal.reisinger.pictures"
                           disabled={!canEdit} {...register('frontend_url')} />
                    {errors.frontend_url &&
                        <span className="text-error text-xs mt-1">{errors.frontend_url.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Primärfarbe (Hex)</span></label>
                    <input type="text" className="input input-bordered font-mono" placeholder="#1E5631"
                           disabled={!canEdit} {...register('primary_color')} />
                    {errors.primary_color &&
                        <span className="text-error text-xs mt-1">{errors.primary_color.message}</span>}
                </div>

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Sekundärfarbe (Hex)</span></label>
                    <input type="text" className="input input-bordered font-mono" placeholder="#A4B494"
                           disabled={!canEdit} {...register('secondary_color')} />
                    {errors.secondary_color &&
                        <span className="text-error text-xs mt-1">{errors.secondary_color.message}</span>}
                </div>

                <div className="form-control md:col-span-2">
                    <label className="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" className="toggle toggle-primary" disabled={!canEdit}
                               {...register('features.orgs')} />
                        <span className="label-text font-bold">Organisationen-Feature aktivieren</span>
                    </label>
                </div>
            </div>

            <div className="mt-6 border-t border-base-300 pt-6 flex items-center gap-4">
                <button type="submit" disabled={isSubmitting || !canEdit} className="btn btn-primary px-8">
                    {isSubmitting && <span className="loading loading-spinner loading-sm"></span>}
                    Speichern
                </button>
                <button type="button" disabled={isSubmitting || !canEdit} className="btn btn-ghost" onClick={onReset}>
                    Auf Standard zurücksetzen
                </button>
                {!canEdit && (
                    <span className="text-sm opacity-60">Nur Super-Admins können diese Einstellungen bearbeiten.</span>
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
                    <span className="iconify mdi--palette text-primary text-3xl"></span> Markeneinstellungen
                </h2>
                <p className="text-sm opacity-70 mb-6">
                    Überschreibe die konfigurierbaren Markeneinstellungen pro Marke. Leere oder zurückgesetzte Felder
                    fallen auf den Konfigurations-Standard zurück.
                </p>

                {isLoading && <span className="loading loading-spinner loading-md"></span>}
                {error && (
                    <div className="alert alert-error shadow-sm">
                        <span>Markeneinstellungen konnten nicht geladen werden.</span>
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
