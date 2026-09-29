import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useId, useState } from 'react';
import { useSWRConfig } from 'swr';
import { useAuth } from '../../../logic/useAuth';
import { usePermissions } from '../../../logic/usePermissions';
import { apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import ShowOncePassword from './ShowOncePassword';

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

/**
 * The profile update response (`PUT /api/auth/profile`).
 *
 * `ftp_password` is present exactly once, and only when the submitted
 * `ftp_slug` replaced an existing account: the backend treats that rename as a
 * password reset (`AuthController::updateProfile`), deletes the old SFTPGo
 * account, provisions a new one and returns the fresh password a single time.
 * It is genuinely optional — an unchanged slug, the field left empty, or a
 * request that never reached SFTPGo (which fails closed) all answer without it,
 * so the success path must not assume the field exists.
 */
interface ProfileUpdateResponse {
    success: boolean;
    ftp_password?: string;
    ftp_password_note?: string;
}

export default function ProfileSettingsCard() {
    "use no memo";
    const { user, mutate: mutateUser } = useAuth();
    const { mutate: mutateCache } = useSWRConfig();
    const { isPhotographer } = usePermissions();
    const { showToast } = useUI();
    const profileFormId = useId();
    const [newPassword, setNewPassword] = useState<string | null>(null);
    const [passwordNotice, setPasswordNotice] = useState<string | null>(null);
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

            const response = await apiMutate<ProfileUpdateResponse>('/api/auth/profile', 'PUT', payload);
            await mutateUser();

            // A slug change also changes `ftp_folder`, which the FTP inbox reads
            // from GET /api/management/ftp/status under its own SWR key. Without
            // this invalidation the dashboard re-mounts inside SWR's deduping
            // window and serves the stale folder it cached at login — the
            // photographer then sees the old account name next to the new one.
            if (isPhotographer) {
                await mutateCache('/api/management/ftp/status', undefined, { revalidate: true });
            }

            // A slug change is a password reset: the backend hands the new camera
            // password over exactly once. Dropping it here is the bug this branch
            // closes — the account would keep working while nobody knows the
            // password, and the rate-limited reset endpoint would be the only way
            // back. Both halves are required: a response without the note is not
            // something this form can render, and a half-shown secret is worse
            // than none, so the panel only appears for a complete receipt.
            if (typeof response.ftp_password === 'string' && response.ftp_password !== '') {
                setNewPassword(response.ftp_password);
                setPasswordNotice(response.ftp_password_note ?? null);
            }

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
                                <span className="label-text font-bold"><Trans>FTP Upload Ordner (Slug)</Trans></span>
                                <span className="label-text-alt opacity-70"><Trans>Der FTP-Login für deine Kamera. Kleinbuchstaben, Ziffern, - und _, 3 bis 32 Zeichen. Muss eindeutig sein.</Trans></span>
                            </label>
                            <div className="join w-full">
                                <span className="btn no-animation join-item bg-base-300 border-base-300 font-mono text-sm px-3 opacity-70 cursor-default">/</span>
                                <input
                                    id={ftpSlugInputId}
                                    type="text"
                                    placeholder={t`z.B. max`}
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
                            <span className="label-text-alt opacity-70"><Trans>Dieser Wert wird in neue Bilder geschrieben, falls die Galerie Metadaten anwendet.</Trans></span>
                        </label>
                        <input
                            id={copyrightInputId}
                            type="text"
                            placeholder={t`z.B. Max Mustermann`}
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

                {newPassword !== null && passwordNotice !== null && (
                    <ShowOncePassword
                        password={newPassword}
                        notice={passwordNotice}
                        onDismiss={() => {
                            setNewPassword(null);
                            setPasswordNotice(null);
                        }}
                    />
                )}
            </div>
        </div>
    );
}
