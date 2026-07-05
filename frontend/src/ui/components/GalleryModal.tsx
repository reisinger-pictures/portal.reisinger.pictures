import { Gallery, FlatGroup, GalleryMetadataOpts } from '../../logic/useGalleries';
import { Tenant } from '../../logic/useTenants';
import { useUI } from './UIContext';
import { useEffect } from 'react';
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
    name: z.string().min(1, 'Name ist erforderlich'),
    slug: z.string(),
    type: z.enum(['selection', 'delivery']),
    is_public: z.boolean(),
    is_live: z.boolean(),
    gallery_group_id: z.string(),
    password: z.string().optional(),
    expires_at: z.string().optional(),
    tenant_id: z.string().optional(),
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
    onCreate: (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: GalleryMetadataOpts) => Promise<void>;
    onUpdate: (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: GalleryMetadataOpts) => Promise<void>;
    onDelete: (id: string) => Promise<void>;
}

export default function GalleryModal({ isOpen, onClose, onOpenGroupModal, availableGroups, editingGallery, defaultGroupId, onCreate, onUpdate, onDelete }: Props) {
    const { showToast, confirm } = useUI();
    const { data: tenants, isLoading } = useSWR<Tenant[]>('/api/management/tenants', fetcher);

    const { register, handleSubmit, reset, setValue, control, formState: { isSubmitting, dirtyFields } } = useForm<GalleryFormValues>({
        resolver: zodResolver(gallerySchema),
        defaultValues: {
            name: '', slug: '', type: 'delivery', is_public: false, is_live: false, gallery_group_id: '', password: '', expires_at: '', is_free_download: false, is_editorial_only: false, is_hidden: false
        }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGallery?.name || '',
                tenant_id: editingGallery?.tenant_id || '',
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
                await onUpdate(editingGallery.id, data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts);
                showToast('success', 'Galerie erfolgreich aktualisiert.');
            } else {
                await onCreate(data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts);
                showToast('success', 'Galerie erfolgreich erstellt.');
            }
            onClose();
        } catch (e: unknown) {
            showToast('error', (e as Error).message || 'Fehler beim Speichern');
        }
    };

    const handleDelete = async () => {
        if (!editingGallery) return;
        if (await confirm({ title: 'Galerie löschen?', message: 'Diese Galerie inklusive aller Bilder wirklich löschen? Dieser Schritt kann nicht rückgängig gemacht werden!', confirmText: 'Unwiderruflich löschen', confirmColor: 'error' })) {
            try {
                await onDelete(editingGallery.id);
                showToast('success', 'Galerie erfolgreich gelöscht.');
                onClose();
            } catch (e: unknown) {
                showToast('error', (e as Error).message || 'Fehler beim Löschen');
            }
        }
    };

    const modalRef = useFocusTrap<HTMLDialogElement>(isOpen && !isLoading);

    if (!isOpen) return null;
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <ModalDialogShell
            title={editingGallery ? 'Galerie bearbeiten' : 'Neue Galerie'}
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
                    Ordner / Meta-Galerie erstellen
                </button>
            ) : undefined}
        >
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold">Name der Galerie</span></label>
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
                    <label className="label"><span className="label-text font-bold">URL Slug</span></label>
                    <input type="text" {...register('slug')} onChange={(e) => setValue('slug', toSlug(e.target.value), {shouldDirty: true, shouldTouch: true})} className="input input-bordered w-full text-sm font-mono opacity-70" />
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold">Galerie-Typ</span></label>
                    <select {...register('type')}
                            onChange={(e) => {
                                setValue('type', e.target.value as 'delivery' | 'selection', { shouldDirty: true });
                                if (e.target.value === 'selection') {
                                    setValue('is_live', false);
                                    setValue('is_public', false);
                                }
                            }}
                            className="select select-bordered w-full">
                        <option value="delivery">Delivery (Downloads)</option>
                        <option value="selection">Auswahl (Ratings)</option>
                    </select>
                </div>
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold">Sichtbarkeit</span></label>
                    <select disabled={isVisibilityForced} value={isVisibilityForced ? (forcedVisibility ? 'true' : 'false') : (watchIsPublic ? 'true' : 'false')} onChange={e => setValue('is_public', e.target.value === 'true')} className="select select-bordered w-full">
                        <option value="false">Privat (Nur mit Link / Passwort)</option>
                        <option value="true">Öffentlich (Für alle sichtbar)</option>
                    </select>
                    {isVisibilityForced && (
                        <label className="label pt-1 pb-0">
                            <span className="label-text-alt text-warning leading-tight whitespace-normal break-words">
                                {watchType === 'selection' ? 'Bewertungs-Galerien sind zwingend privat.' : 'Wird durch Meta-Galerie erzwungen'}
                            </span>
                        </label>
                    )}
                </div>
            </div>

            
            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold">Zugeordnete Organisation (Verschieben)</span></label>
                <select {...register('tenant_id')} className="select select-bordered w-full">
                    <option value="">-- Keine spezifische Organisation --</option>
                    {tenants?.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
            </div>

            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold">In welchem Ordner soll die Galerie liegen?</span></label>
                <select {...register('gallery_group_id')} className="select select-bordered w-full">
                    <option value="">-- Oberste Ebene (Root) --</option>
                    {availableGroups.map(g => <option key={g.id} value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                </select>
            </div>

            {watchType === 'delivery' && (
                <div className="space-y-3 mb-4">
                    <CheckboxGroup items={[
                        { name: 'is_free_download', label: 'Kostenlosen Download erlauben', description: 'Deaktiviert Wasserzeichen & Lizenzen. Direkter Download für Gäste.' },
                        { name: 'is_editorial_only', label: 'Nur für redaktionelle Nutzung (Shop)', description: 'Sperrt kommerzielle Lizenzen im Checkout.' },
                        { name: 'is_hidden', label: 'Im Frontend verstecken', description: 'Wird nicht in Suchergebnissen oder Feeds gelistet.' },
                    ]} register={register} />

                    <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-base-300 w-full">
                        <input type="checkbox" {...register('is_live')} className="checkbox checkbox-primary" />
                        <div>
                            <span className="label-text font-bold block">LIVE Galerie</span>
                            <span className="label-text-alt opacity-70 leading-tight block mt-1">Automatischer Refresh für Besucher alle 10 Sekunden.</span>
                        </div>
                    </label>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 pt-4 border-t border-base-300">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold">Passwort (Optional)</span></label>
                    <input type="text" {...register('password')} className="input input-bordered w-full" placeholder={editingGallery ?"Leer = Aktuelles behalten" :"Leer = Nur Magic Link"} />
                </div>
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold">Ablaufdatum (Optional)</span></label>
                    <input type="date" {...register('expires_at')} className="input input-bordered w-full" />
                </div>
            </div>
        </ModalDialogShell>
    );
}