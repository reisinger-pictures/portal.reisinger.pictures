import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useId } from 'react';
import { useAuth } from '../../../logic/useAuth';
import { usePermissions } from '../../../logic/usePermissions';
import { apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

/**
 * `ftp_slug` becomes the SFTPGo account name, so the persisted value has to match
 * `^[a-z0-9][a-z0-9_-]{2,31}$` — the single source of truth for that is
 * `backend/app/Support/FtpSlug.php` (P1-M21).
 *
 * The backend is authoritative and additionally normalises cosmetics (case,
 * umlauts, whitespace) before validating. This gate deliberately asks for the
 * *final* form instead of mirroring that normaliser: mirroring it would duplicate
 * the rule across two languages, and letting a value change after it was saved is
 * the one thing a login name must not do. A dot, an `@` or a slash therefore gets
 * a field error rather than a quietly different account name.
 */
const FTP_SLUG_PATTERN = /^[a-z0-9][a-z0-9_-]{2,31}$/;

const createProfileSchema = () => z.object({
    name: z.string().min(1, t`Name ist erforderlich`),
    metadata_copyright: z.string().optional(),
    ftp_slug: z.string().optional().refine(
        // An empty field means "leave the account name alone" — the field is
        // optional and the endpoint is not called with a slug in that case.
        (value) => value === undefined || value === '' || FTP_SLUG_PATTERN.test(value),
        t`Nur Kleinbuchstaben, Ziffern, - und _, 3 bis 32 Zeichen, Start mit Buchstabe oder Ziffer.`
    )
});
type ProfileFormValues = z.infer<ReturnType<typeof createProfileSchema>>;

export default function ProfileSettingsCard() {
    "use no memo";
    const { user, mutate: mutateUser } = useAuth();
    const { isPhotographer } = usePermissions();
    const { showToast } = useUI();
    const profileFormId = useId();
    const nameInputId = `${profileFormId}-name`;
    const ftpSlugInputId = `${profileFormId}-ftp-slug`;
    const copyrightInputId = `${profileFormId}-metadata-copyright`;
    const profileSchema = createProfileSchema();

    const profileForm = useForm<ProfileFormValues>({
        resolver: zodResolver(profileSchema),
        defaultValues: { name: '', metadata_copyright: '', ftp_slug: '' }
    });

    const { formState: { errors, isDirty } } = profileForm;
    const ftpSlugError = errors.ftp_slug;

    useEffect(() => {
        // Nur beim initialen Laden hydrieren — ein bereits "dirty" Formular (User
        // hat getippt) darf der SWR-Hydration nicht den Wert überschreiben, sonst
        // wird z.B. ftp_slug/metadata_copyright leer persistiert (E2E-Flake).
        if (user && !isDirty) {
            profileForm.reset({
                name: user.name || '',
                metadata_copyright: user.metadata_copyright || '',
                ftp_slug: user.ftp_slug || ''
            });
        }
    }, [user, profileForm, isDirty]);

    const onSubmit = async (data: ProfileFormValues) => {
        try {
            const payload: Record<string, string | undefined> = { name: data.name, metadata_copyright: data.metadata_copyright };
            if (isPhotographer) payload.ftp_slug = data.ftp_slug;
            
            await apiMutate('/api/auth/profile', 'PUT', payload);
            await mutateUser();
            showToast('success', t`Profil aktualisiert`);
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        }
    };

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4"><Trans>Profil & Standardwerte</Trans></h2>
                
                <form onSubmit={profileForm.handleSubmit(onSubmit)} className="space-y-6" noValidate>
                    <div className="form-control">
                        <label className="label" htmlFor={nameInputId}><span className="label-text font-bold"><Trans>Dein Name</Trans></span></label>
                        <input
                            id={nameInputId}
                            type="text"
                            required
                            {...profileForm.register('name')}
                            className={`input input-bordered w-full ${errors.name ? 'input-error' : ''}`}
                        />
                    </div>
                    
                    {isPhotographer && (
                        <div className="form-control">
                            <label className="label" htmlFor={ftpSlugInputId}>
                                <span className="label-text font-bold">FTP Upload Ordner (Slug)</span>
                                <span className="label-text-alt opacity-70">Der FTP-Login für deine Kamera. Kleinbuchstaben, Ziffern, - und _, 3 bis 32 Zeichen. Muss eindeutig sein.</span>
                            </label>
                            <div className="join w-full">
                                <span className="btn no-animation join-item bg-base-300 border-base-300 font-mono text-sm px-3 opacity-70 cursor-default">/</span>
                                <input
                                    id={ftpSlugInputId}
                                    type="text"
                                    placeholder="z.B. max"
                                    aria-invalid={ftpSlugError ? true : undefined}
                                    {...profileForm.register('ftp_slug')} 
                                    className={`input input-bordered join-item w-full font-mono text-sm ${ftpSlugError ? 'input-error' : ''}`}
                                />
                            </div>
                            {ftpSlugError && <span className="text-error text-xs mt-1">{ftpSlugError.message}</span>}
                        </div>
                    )}
                    <div className="form-control">
                        <label className="label" htmlFor={copyrightInputId}>
                            <span className="label-text font-bold"><Trans>Standard-Urheber (IPTC Copyright)</Trans></span>
                            <span className="label-text-alt opacity-70">Dieser Wert wird in neue Bilder geschrieben, falls die Galerie Metadaten anwendet.</span>
                        </label>
                        <input
                            id={copyrightInputId}
                            type="text"
                            placeholder="z.B. Max Mustermann"
                            {...profileForm.register('metadata_copyright')} 
                            className="input input-bordered w-full"
                        />
                    </div>
                    <div>
                        <button type="submit" disabled={profileForm.formState.isSubmitting} className="btn btn-primary">
                            {profileForm.formState.isSubmitting ? <span className="loading loading-spinner"></span> : 'Profil speichern'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
