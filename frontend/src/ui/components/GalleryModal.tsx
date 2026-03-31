import { useEffect } from 'react';
import { Gallery, FlatGroup } from '../../logic/useGalleries';
import { useUI } from './UIContext';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const gallerySchema = z.object({
    name: z.string().min(1, 'Name ist erforderlich'),
    slug: z.string(),
    type: z.enum(['selection', 'delivery']),
    is_public: z.boolean(),
    is_live: z.boolean(),
    gallery_group_id: z.string(),
    password: z.string().optional(),
    expires_at: z.string().optional()
});
type GalleryFormValues = z.infer<typeof gallerySchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onOpenGroupModal: () => void;
    availableGroups: FlatGroup[];
    editingGallery?: Gallery | null;
    defaultGroupId?: string | null;
    onCreate: (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: any) => Promise<void>;
    onUpdate: (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: any) => Promise<void>;
    onDelete: (id: string) => Promise<void>;
}

const toSlug = (text: string) => text.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '-').replace(/^-+/, '');

export default function GalleryModal({ isOpen, onClose, onOpenGroupModal, availableGroups, editingGallery, defaultGroupId, onCreate, onUpdate, onDelete }: Props) {
    const { showToast, confirm } = useUI();

    const { register, handleSubmit, reset, watch, setValue, formState: { isSubmitting, dirtyFields } } = useForm<GalleryFormValues>({
        resolver: zodResolver(gallerySchema),
        defaultValues: {
            name: '', slug: '', type: 'delivery', is_public: false, is_live: false, gallery_group_id: '', password: '', expires_at: ''
        }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGallery?.name || '',
                slug: editingGallery?.slug || '',
                type: editingGallery?.type || 'delivery',
                is_public: editingGallery?.is_public || false,
                is_live: editingGallery?.is_live || false,
                gallery_group_id: editingGallery?.gallery_group_id || defaultGroupId || '',
                password: '',
                expires_at: editingGallery?.expires_at ? editingGallery.expires_at.split('T')[0] : ''
            });
        }
    }, [isOpen, editingGallery, reset, defaultGroupId]);

    const watchName = watch('name');
    useEffect(() => {
        if (!editingGallery && !dirtyFields.slug && watchName) {
            setValue('slug', toSlug(watchName));
        }
    }, [watchName, editingGallery, dirtyFields.slug, setValue]);

    const watchType = watch('type');
    const watchGroupId = watch('gallery_group_id');

    useEffect(() => {
        if (watchType === 'selection') {
            setValue('is_live', false);
            setValue('is_public', false);
        }
    }, [watchType, setValue]);

    const selectedParent = availableGroups.find(g => g.id === (watchGroupId === '' ? null : watchGroupId));
    let isVisibilityForced = selectedParent?.is_public !== undefined && selectedParent?.is_public !== null;
    let forcedVisibility = selectedParent?.is_public;

    if (watchType === 'selection') {
        isVisibilityForced = true;
        forcedVisibility = false;
    }

    const onSubmit = async (data: GalleryFormValues) => {
        const pId = data.gallery_group_id === '' ? null : data.gallery_group_id;
        const metaOpts = {}; // Metadaten werden jetzt isoliert gemanaged

        try {
            if (editingGallery) {
                await onUpdate(editingGallery.id, data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts);
                showToast('success', 'Galerie erfolgreich aktualisiert.');
            } else {
                await onCreate(data.name, data.slug, data.type, data.is_live, data.is_public, pId, data.password, data.expires_at, metaOpts);
                showToast('success', 'Galerie erfolgreich erstellt.');
            }
            onClose();
        } catch (e: any) {
            showToast('error', e.message || 'Fehler beim Speichern');
        }
    };

    const handleDelete = async () => {
        if (!editingGallery) return;
        if (await confirm({ title: 'Galerie löschen?', message: 'Diese Galerie inklusive aller Bilder wirklich löschen? Dieser Schritt kann nicht rückgängig gemacht werden!', confirmText: 'Unwiderruflich löschen', confirmColor: 'error' })) {
            try {
                await onDelete(editingGallery.id);
                showToast('success', 'Galerie erfolgreich gelöscht.');
                onClose();
            } catch (e: any) {
                showToast('error', e.message || 'Fehler beim Löschen');
            }
        }
    };

    if (!isOpen) return null;

    return (
        <dialog className="modal modal-open z-[60]">
            <div className="modal-box max-w-2xl relative">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>

                <div className="flex justify-between items-center mb-6 mr-8">
                    <h3 className="font-bold text-xl flex items-center gap-2">
                        <span className="iconify mdi--image-multiple text-primary"></span>
                        {editingGallery ? 'Galerie bearbeiten' : 'Neue Galerie'}
                    </h3>
                    {!editingGallery && (
                        <button type="button" className="btn btn-xs btn-outline" onClick={() => { onClose(); onOpenGroupModal(); }}>
                            Ordner / Meta-Galerie erstellen
                        </button>
                    )}
                </div>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="flex flex-col md:flex-row gap-4 mb-4">
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">Name der Galerie</span></label>
                            <input type="text" {...register('name')} className="input input-bordered w-full" />
                        </div>
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">URL Slug</span></label>
                            <input type="text" {...register('slug')} onChange={(e) => setValue('slug', toSlug(e.target.value), {shouldDirty: true, shouldTouch: true})} className="input input-bordered w-full text-sm font-mono opacity-70" />
                        </div>
                    </div>

                    <div className="flex flex-col md:flex-row gap-4 mb-4">
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">Galerie-Typ</span></label>
                            <select {...register('type')} className="select select-bordered w-full">
                                <option value="delivery">Delivery (Downloads)</option>
                                <option value="selection">Auswahl (Ratings)</option>
                            </select>
                        </div>
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">Sichtbarkeit</span></label>
                            <select disabled={isVisibilityForced} value={isVisibilityForced ? (forcedVisibility ? 'true' : 'false') : (watch('is_public') ? 'true' : 'false')} onChange={e => setValue('is_public', e.target.value === 'true')} className="select select-bordered w-full">
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
                        <label className="label"><span className="label-text font-bold">In welchem Ordner soll die Galerie liegen?</span></label>
                        <select {...register('gallery_group_id')} className="select select-bordered w-full">
                            <option value="">-- Oberste Ebene (Root) --</option>
                            {availableGroups.map(g => <option key={g.id} value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                        </select>
                    </div>

                    {watchType === 'delivery' && (
                        <div className="form-control w-full mb-4">
                            <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                                <input type="checkbox" {...register('is_live')} className="checkbox checkbox-primary" />
                                <div>
                                    <span className="label-text font-bold block">LIVE Galerie</span>
                                    <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">Die Galerie aktualisiert sich für Besucher automatisch alle 10s.</span>
                                </div>
                            </label>
                        </div>
                    )}

                    <div className="flex flex-col md:flex-row gap-4 mb-6 pt-4 border-t border-base-300">
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">Passwort (Optional)</span></label>
                            <input type="text" {...register('password')} className="input input-bordered w-full" placeholder={editingGallery ? "Leer = Aktuelles behalten" : "Leer = Nur Magic Link"} />
                        </div>
                        <div className="form-control w-full md:w-1/2">
                            <label className="label"><span className="label-text font-bold">Ablaufdatum (Optional)</span></label>
                            <input type="date" {...register('expires_at')} className="input input-bordered w-full" />
                        </div>
                    </div>

                    <div className="modal-action flex justify-between mt-8">
                        {editingGallery ? (
                            <button type="button" className="btn btn-outline btn-error" onClick={handleDelete}>Löschen</button>
                        ) : <div></div>}
                        <div>
                            <button type="button" className="btn btn-ghost mr-2" onClick={onClose}>Abbrechen</button>
                            <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                                {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </dialog>
    );
}