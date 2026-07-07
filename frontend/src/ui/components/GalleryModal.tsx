import { Gallery, FlatGroup, GalleryMetadataOpts } from '../../logic/useGalleries';
import { Org } from '../../logic/useOrgs';
import { useUI } from './UIContext';
import { useEffect } from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useFocusTrap } from '../../logic/useFocusTrap';
import { useForm, useWatch } from 'react-hook-form';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toSlug } from '../../logic/utils';
import CheckboxGroup from '../components/CheckboxGroup';
import ModalDialogShell from './ModalDialogShell';

const gallerySchema = z.object({
    name: z.string().min(1, t`Name ist erforderlich`),
    slug: z.string(),
    type: z.enum(['selection', 'delivery']),
    is_public: z.boolean(),
    is_live: z.boolean(),
    gallery_group_id: z.string(),
    password: z.string().optional(),
    expires_at: z.string().optional(),
    org_ids: z.array(z.string()),
    is_free_download: z.boolean().optional(),
    is_editorial_only: z.boolean().optional(),
    is_hidden: z.boolean().optional()
});
type GalleryFormValues = z.infer<typeof gallerySchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onOpenGroupModal: () => void;
    availableGroups: FlatGroup[];
    editingGallery?: Gallery | null;
    defaultGroupId?: string | null;
    onCreate: (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: GalleryMetadataOpts, orgIds?: string[]) => Promise<void>;
    onUpdate: (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: GalleryMetadataOpts, orgIds?: string[]) => Promise<void>;
    onDelete: (id: string) => Promise<void>;
}

export default function GalleryModal({ isOpen, onClose, onOpenGroupModal, availableGroups, editingGallery, defaultGroupId, onCreate, onUpdate, onDelete }: Props) {
    const { showToast, confirm } = useUI();
    const { data: orgs, isLoading } = useSWR<Org[]>('/api/management/orgs', fetcher);

    const { register, handleSubmit, reset, setValue, control, formState: { isSubmitting, dirtyFields } } = useForm<GalleryFormValues>({
        resolver: zodResolver(gallerySchema),
        defaultValues: {
            name: '', slug: '', type: 'delivery', is_public: false, is_live: false, gallery_group_id: '', password: '', expires_at: '', org_ids: [], is_free_download: false, is_editorial_only: false, is_hidden: false
        }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGallery?.name || '',
                org_ids: editingGallery?.org_ids || [],
                slug: editingGallery?.slug || '',
                type: editingGallery?.type || 'delivery',
                is_public: editingGallery?.is_public || false,
                is_live: editingGallery?.is_live || false,
                gallery_group_id: editingGallery?.gallery_group_id || defaultGroupId || '',
                password: '',
                expires_at: editingGallery?.expires_at ? editingGallery.expires_at.split('T')[0] : '',
                is_free_download: !!editingGallery?.is_free_download,
                is_editorial_only: !!editingGallery?.is_editorial_only,
                is_hidden: !!editingGallery?.is_hidden
            });
        }
    }, [isOpen, editingGallery, reset, defaultGroupId]);

    const watchType = useWatch({ control, name: 'type' });
    const watchGroupId = useWatch({ control, name: 'gallery_group_id' });
    const watchIsPublic = useWatch({ control, name: 'is_public' });
    const watchOrgIds = useWatch({ control, name: 'org_ids' });

    const selectedParent = availableGroups.find(g => g.id === (watchGroupId === '' ? null : watchGroupId));
    let isVisibilityForced = selectedParent?.is_public !== undefined && selectedParent?.is_public !== null;
    let forcedVisibility = selectedParent?.is_public;

    if (watchType === 'selection') {
        isVisibilityForced = true;
        forcedVisibility = false;
    }

    const onSubmit = async (data: GalleryFormValues) => {
        const pId = data.gallery_group_id === '' ? null : data.gallery_group_id;
        const metaOpts = { 
            is_free_download: data.is_free_download,
            is_editorial_only: data.is_editorial_only,
            is_hidden: data.is_hidden
        };

        try {
            if (editingGallery) {
                await onUpdate(editingGallery.id, data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts, data.org_ids);
                showToast('success', t`Galerie erfolgreich aktualisiert.`);
            } else {
                await onCreate(data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts, data.org_ids);
                showToast('success', t`Galerie erfolgreich erstellt.`);
            }
            onClose();
        } catch (e: unknown) {
            showToast('error', (e as Error).message || t`Fehler beim Speichern`);
        }
    };

    const handleDelete = async () => {
        if (!editingGallery) return;
        if (await confirm({ title: t`Galerie löschen?`, message: t`Diese Galerie inklusive aller Bilder wirklich löschen? Dieser Schritt kann nicht rückgängig gemacht werden!`, confirmText: t`Unwiderruflich löschen`, confirmColor: 'error' })) {
            try {
                await onDelete(editingGallery.id);
                showToast('success', t`Galerie erfolgreich gelöscht.`);
                onClose();
            } catch (e: unknown) {
                showToast('error', (e as Error).message || t`Fehler beim Löschen`);
            }
        }
    };

    const modalRef = useFocusTrap<HTMLDialogElement>(isOpen && !isLoading);

    if (!isOpen) return null;
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <ModalDialogShell
            title={editingGallery ? <Trans>Galerie bearbeiten</Trans> : <Trans>Neue Galerie</Trans>}
            icon="mdi--image-multiple"
            onClose={onClose}
            onDelete={handleDelete}
            editing={!!editingGallery}
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
            modalRef={modalRef}
            maxWidth="2xl"
            secondaryAction={!editingGallery ? (
                <button type="button" className="btn btn-xs btn-outline" onClick={() => { onClose(); onOpenGroupModal(); }}>
                    <Trans>Ordner / Meta-Galerie erstellen</Trans>
                </button>
            ) : undefined}
        >
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Name der Galerie</Trans></span></label>
                    <input type="text" {...register('name')}
                           onChange={(e) => {
                               setValue('name', e.target.value, { shouldDirty: true });
                               if (!editingGallery && !dirtyFields.slug && e.target.value) {
                                   setValue('slug', toSlug(e.target.value));
                               }
                           }}
                           className="input input-bordered w-full" />
                </div>
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>URL Slug</Trans></span></label>
                    <input type="text" {...register('slug')} onChange={(e) => setValue('slug', toSlug(e.target.value), {shouldDirty: true, shouldTouch: true})} className="input input-bordered w-full text-sm font-mono opacity-70" />
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Galerie-Typ</Trans></span></label>
                    <select {...register('type')}
                            onChange={(e) => {
                                setValue('type', e.target.value as 'delivery' | 'selection', { shouldDirty: true });
                                if (e.target.value === 'selection') {
                                    setValue('is_live', false);
                                    setValue('is_public', false);
                                }
                            }}
                            className="select select-bordered w-full">
                        <option value="delivery"><Trans>Delivery (Downloads)</Trans></option>
                        <option value="selection"><Trans>Auswahl (Ratings)</Trans></option>
                    </select>
                </div>
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Sichtbarkeit</Trans></span></label>
                    <select disabled={isVisibilityForced} value={isVisibilityForced ? (forcedVisibility ? 'true' : 'false') : (watchIsPublic ? 'true' : 'false')} onChange={e => setValue('is_public', e.target.value === 'true')} className="select select-bordered w-full">
                        <option value="false"><Trans>Privat (Nur mit Link / Passwort)</Trans></option>
                        <option value="true"><Trans>Öffentlich (Für alle sichtbar)</Trans></option>
                    </select>
                    {isVisibilityForced && (
                        <label className="label pt-1 pb-0">
                            <span className="label-text-alt text-warning leading-tight whitespace-normal break-words">
                                {watchType === 'selection' ? t`Bewertungs-Galerien sind zwingend privat.` : t`Wird durch Meta-Galerie erzwungen`}
                            </span>
                        </label>
                    )}
                </div>
            </div>

            
            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold"><Trans>Zugeordnete Organisationen</Trans></span></label>
                <div className="flex flex-wrap gap-3 p-3 bg-base-200 rounded-box border border-base-300">
                    {orgs?.map(org => (
                        <label key={org.id} className="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={(watchOrgIds ?? []).includes(org.id)}
                                onChange={() => {
                                    const current = watchOrgIds ?? [];
                                    if (current.includes(org.id)) {
                                        setValue('org_ids', current.filter(id => id !== org.id), { shouldDirty: true });
                                    } else {
                                        setValue('org_ids', [...current, org.id], { shouldDirty: true });
                                    }
                                }}
                                className="checkbox checkbox-sm checkbox-primary"
                            />
                            <span className="text-sm">{org.name}</span>
                        </label>
                    ))}
                    {(!orgs || orgs.length === 0) && (
                        <span className="text-sm opacity-50 italic"><Trans>Keine Organisationen verfügbar</Trans></span>
                    )}
                </div>
            </div>

            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold"><Trans>In welchem Ordner soll die Galerie liegen?</Trans></span></label>
                <select {...register('gallery_group_id')} className="select select-bordered w-full">
                    <option value="">-- <Trans>Oberste Ebene (Root)</Trans> --</option>
                    {availableGroups.map(g => <option key={g.id} value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                </select>
            </div>

            {watchType === 'delivery' && (
                <div className="space-y-3 mb-4">
                    <CheckboxGroup items={[
                        { name: 'is_free_download', label: t`Kostenlosen Download erlauben`, description: t`Deaktiviert Wasserzeichen & Lizenzen. Direkter Download für Gäste.` },
                        { name: 'is_editorial_only', label: t`Nur für redaktionelle Nutzung (Shop)`, description: t`Sperrt kommerzielle Lizenzen im Checkout.` },
                        { name: 'is_hidden', label: t`Im Frontend verstecken`, description: t`Wird nicht in Suchergebnissen oder Feeds gelistet.` },
                    ]} register={register} />

                    <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-base-300 w-full hover:bg-base-300/50 transition-colors">
                        <input type="checkbox" {...register('is_live')} className="checkbox checkbox-primary shrink-0" />
                        <div>
                            <span className="label-text font-bold block"><Trans>LIVE Galerie</Trans></span>
                            <span className="label-text-alt opacity-70 leading-tight block mt-1"><Trans>Automatischer Refresh für Besucher alle 10 Sekunden.</Trans></span>
                        </div>
                    </label>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 pt-4 border-t border-base-300">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Passwort</Trans></span></label>
                    <input type="text" {...register('password')} className="input input-bordered w-full" placeholder={editingGallery ? t`Leer = Aktuelles behalten` : t`Leer = Nur Magic Link`} />
                </div>
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Ablaufdatum</Trans></span></label>
                    <input type="date" {...register('expires_at')} className="input input-bordered w-full" />
                </div>
            </div>
        </ModalDialogShell>
    );
}