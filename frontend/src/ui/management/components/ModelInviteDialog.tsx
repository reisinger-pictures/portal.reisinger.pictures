import { useState } from 'react';
import { Link } from 'react-router-dom';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { z } from 'zod';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import ErrorMessage from '../../components/ErrorMessage';
import EmptyState from '../../components/EmptyState';
import { useUI } from '../../components/UIContext';
import { usePermissions } from '../../../logic/usePermissions';
import {
    useModelInvites,
    inviteLinkFromCreate,
    type ModelInvite,
    type ModelInviteStatus,
} from '../../../logic/useModelInvites';

/**
 * Copies text via the async Clipboard API, falling back to a hidden textarea +
 * `execCommand('copy')` on browsers where the API is unavailable (e.g. insecure
 * contexts). Kept at module scope so the component stays compiler-friendly.
 */
async function copyTextToClipboard(text: string): Promise<void> {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.className = 'fixed opacity-0 pointer-events-none';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);
    if (!copied) throw new Error('Clipboard copy failed');
}

const createInviteSchema = () =>
    z.object({
        // Optional: primary flow is a copyable magic link, the email is only an
        // additional delivery channel.
        label: z.string().trim().max(255, t`Name / Notiz darf höchstens 255 Zeichen lang sein.`),
        email: z.union([
            z.literal(''),
            z.string().trim().email(t`Bitte eine gültige E-Mail-Adresse angeben.`),
        ]),
    });

type CreateInviteValues = z.infer<ReturnType<typeof createInviteSchema>>;

const STATUS_BADGE: Record<ModelInviteStatus, string> = {
    open: 'badge-success',
    redeemed: 'badge-info',
    expired: 'badge-ghost',
};

function statusLabel(status: ModelInviteStatus): string {
    switch (status) {
        case 'open':
            return t`Offen`;
        case 'redeemed':
            return t`Eingelöst`;
        default:
            return t`Abgelaufen`;
    }
}

function formatDate(value: string | null): string {
    if (!value) return '–';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '–';
    return date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

interface Props {
    onClose: () => void;
}

/**
 * Invite creation + magic-link copy + invite list as a modal on the Models page
 * (the former standalone `/admin-model-invites` page).
 */
export default function ModelInviteDialog({ onClose }: Props) {
    const { invites, error, isLoading, createInvite, revokeInvite } = useModelInvites();
    const { showToast, confirm } = useUI();
    const { isAdmin } = usePermissions();
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [createdLink, setCreatedLink] = useState<string | null>(null);
    const [createdEmail, setCreatedEmail] = useState('');

    const schema = createInviteSchema();
    const { register, handleSubmit, reset, formState: { errors } } = useForm<CreateInviteValues>({
        resolver: zodResolver(schema),
        defaultValues: { label: '', email: '' },
    });

    const handleCopyLink = async (link: string) => {
        try {
            await copyTextToClipboard(link);
            showToast('success', t`Link wurde kopiert.`);
        } catch {
            showToast('error', t`Konnte Link nicht kopieren.`);
        }
    };

    const onSubmit = async (values: CreateInviteValues) => {
        setIsSubmitting(true);
        try {
            const response = await createInvite({ email: values.email, label: values.label });
            setCreatedLink(inviteLinkFromCreate(response));
            setCreatedEmail(values.email.trim());
            showToast('success', t`Einladung wurde angelegt.`);
            reset({ label: '', email: '' });
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Erstellen der Einladung.`);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleRevoke = async (invite: ModelInvite) => {
        if (!(await confirm({
            title: t`Einladung widerrufen?`,
            message: t`Der Einladungslink wird ungültig und kann nicht mehr verwendet werden.`,
            confirmColor: 'error',
        }))) return;
        try {
            await revokeInvite(invite.id);
            showToast('success', t`Einladung widerrufen.`);
        } catch {
            showToast('error', t`Fehler beim Widerrufen.`);
        }
    };

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-4xl max-h-90vh overflow-y-auto" data-testid="model-invite-dialog">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose} aria-label={t`Schließen`}>✕</button>

                <h3 className="font-bold text-2xl mb-1 flex items-center gap-2">
                    <span className="iconify mdi--account-plus text-primary"></span>
                    <Trans>Einladung erstellen</Trans>
                </h3>
                <p className="opacity-70 mb-6"><Trans>Erstelle einen kopierbaren Einladungslink und teile ihn z. B. per WhatsApp. Eine E-Mail ist optional.</Trans></p>

                {error && <ErrorMessage message={t`Fehler beim Laden der Einladungen.`} />}

                {isAdmin && (
                    <div className="bg-base-100 border border-base-300 rounded-box p-4 mb-6">
                        <form onSubmit={handleSubmit(onSubmit)} className="grid grid-cols-1 md:grid-cols-2 gap-3 items-start" noValidate>
                            <div className="form-control w-full">
                                <label className="label" htmlFor="model-invite-label">
                                    <span className="label-text font-bold"><Trans>Name / Notiz</Trans></span>
                                </label>
                                <input
                                    id="model-invite-label"
                                    type="text"
                                    placeholder={t`z. B. WhatsApp-Kontakt Anna`}
                                    className={`input input-bordered w-full ${errors.label ? 'input-error' : ''}`}
                                    {...register('label')}
                                />
                                {errors.label && <span className="text-error text-xs mt-1">{errors.label.message}</span>}
                            </div>
                            <div className="form-control w-full">
                                <label className="label" htmlFor="model-invite-email">
                                    <span className="label-text font-bold"><Trans>E-Mail der eingeladenen Person</Trans></span>
                                </label>
                                <input
                                    id="model-invite-email"
                                    type="email"
                                    placeholder={t`name@beispiel.de`}
                                    className={`input input-bordered w-full ${errors.email ? 'input-error' : ''}`}
                                    {...register('email')}
                                />
                                {errors.email && <span className="text-error text-xs mt-1">{errors.email.message}</span>}
                                <span className="label-text-alt opacity-70 mt-1"><Trans>Wird eine E-Mail angegeben, versenden wir den Link zusätzlich per Mail.</Trans></span>
                            </div>
                            <div className="md:col-span-2">
                                <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                                    {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Einladung erstellen</Trans>}
                                </button>
                            </div>
                        </form>

                        {createdLink && (
                            <div className="mt-6 p-4 bg-success/10 border border-success/30 rounded-box" data-testid="model-invite-created">
                                <p className="font-bold text-success mb-2"><Trans>Einladungslink (zum Kopieren)</Trans></p>
                                <div className="flex flex-col sm:flex-row gap-2">
                                    <input
                                        type="text"
                                        readOnly
                                        value={createdLink}
                                        data-testid="model-invite-link"
                                        aria-label={t`Einladungslink`}
                                        className="input input-bordered w-full font-mono text-sm"
                                        onFocus={e => e.currentTarget.select()}
                                    />
                                    <button
                                        type="button"
                                        className="btn btn-success text-white"
                                        onClick={() => handleCopyLink(createdLink)}
                                    >
                                        <span className="iconify mdi--content-copy"></span>
                                        <Trans>Kopieren</Trans>
                                    </button>
                                </div>
                                {createdEmail && (
                                    <p className="text-sm opacity-70 mt-2"><Trans>Zusätzlich per E-Mail versendet.</Trans></p>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <div className="bg-base-100 border border-base-300 rounded-box p-4">
                    <h4 className="font-bold text-lg mb-3"><Trans>Einladungen</Trans></h4>
                    {isLoading ? (
                        <div className="flex justify-center py-6"><span className="loading loading-spinner loading-lg"></span></div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table table-zebra w-full">
                                <thead>
                                    <tr>
                                        <th><Trans>Name / Notiz</Trans></th>
                                        <th><Trans>E-Mail</Trans></th>
                                        <th><Trans>Status</Trans></th>
                                        <th><Trans>Erstellt</Trans></th>
                                        <th><Trans>Läuft ab</Trans></th>
                                        <th><Trans>Eingeladen von</Trans></th>
                                        <th className="text-right"><Trans>Aktionen</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invites?.map(invite => (
                                        <tr key={invite.id}>
                                            <td className="font-medium">{invite.label ?? '–'}</td>
                                            <td>{invite.email ?? '–'}</td>
                                            <td>
                                                {invite.status === 'redeemed' && invite.customer_id ? (
                                                    <Link
                                                        to={`/admin-models?model=${invite.customer_id}`}
                                                        className={`badge ${STATUS_BADGE[invite.status]} hover:opacity-80`}
                                                        data-testid={`model-invite-profile-${invite.id}`}
                                                        onClick={onClose}
                                                    >
                                                        {statusLabel(invite.status)}
                                                    </Link>
                                                ) : (
                                                    <span className={`badge ${STATUS_BADGE[invite.status]}`}>{statusLabel(invite.status)}</span>
                                                )}
                                            </td>
                                            <td>{formatDate(invite.created_at)}</td>
                                            <td>{formatDate(invite.expires_at)}</td>
                                            <td>{invite.invited_by ?? '–'}</td>
                                            <td className="text-right whitespace-nowrap">
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost btn-xs text-info"
                                                    title={t`Link kopieren`}
                                                    aria-label={t`Link kopieren`}
                                                    disabled={invite.status !== 'open' || !invite.link}
                                                    onClick={() => invite.link && handleCopyLink(invite.link)}
                                                >
                                                    <span className="iconify mdi--content-copy"></span>
                                                </button>
                                                {invite.status === 'open' && isAdmin && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-ghost btn-xs text-error"
                                                        onClick={() => handleRevoke(invite)}
                                                    >
                                                        <span className="iconify mdi--cancel"></span>
                                                        <Trans>Widerrufen</Trans>
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {invites?.length === 0 && (
                                        <tr>
                                            <td colSpan={7}>
                                                <EmptyState icon="mdi--account-plus-outline" title={t`Noch keine Einladungen angelegt.`} className="py-10" />
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div className="modal-action">
                    <button type="button" className="btn btn-ghost" data-testid="model-invite-close" onClick={onClose}><Trans>Schließen</Trans></button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
