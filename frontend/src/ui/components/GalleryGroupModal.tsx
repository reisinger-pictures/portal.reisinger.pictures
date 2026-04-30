import { useEffect } from 'react';
import { GalleryGroup, FlatGroup, GalleryGroupExtraOpts } from '../../logic/useGalleries';
import { useUI } from './UIContext';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const groupSchema = z.object({
    name: z.string().min(1, 'Name ist erforderlich'),
    slug: z.string(),
    is_public: z.enum(['null', 'true', 'false']),
    parent_id: z.string(),
    is_free_download: z.boolean().optional(),
    is_editorial_only: z.boolean().optional(),
    is_hidden: z.boolean().optional()
});
type GroupFormValues = z.infer<typeof groupSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    availableGroups: FlatGroup[];
    editingGroup?: GalleryGroup | null;
    defaultParentId?: string | null;
    onCreate: (name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: GalleryGroupExtraOpts) => Promise<void>;
    onUpdate: (id: string, name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: GalleryGroupExtraOpts) => Promise<void>;
    onDelete: (id: string) => Promise<void>;
}

const toSlug = (text: string) => text.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '-').replace(/^-+/, '');

export default function GalleryGroupModal({ isOpen, onClose, availableGroups, editingGroup, defaultParentId, onCreate, onUpdate, onDelete }: Props) {
    const { showToast, confirm } = useUI();

    const { register, handleSubmit, reset, setValue, control, formState: { isSubmitting, dirtyFields } } = useForm<GroupFormValues>({
        resolver: zodResolver(groupSchema),
        defaultValues: { name: '', slug: '', is_public: 'null', parent_id: '', is_free_download: false, is_editorial_only: false, is_hidden: false }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGroup?.name || '',
                slug: editingGroup?.slug || '',
                is_free_download: editingGroup?.is_free_download || false,
                is_editorial_only: editingGroup?.is_editorial_only || false,
                is_hidden: editingGroup?.is_hidden || false,
                is_public: editingGroup?.is_public === null || editingGroup?.is_public === undefined ? 'null' : (editingGroup.is_public ? 'true' : 'false'),
                parent_id: editingGroup?.parent_id || defaultParentId || ''
            });
        }
    }, [isOpen, editingGroup, reset, defaultParentId]);

    const watchName = useWatch({ control, name: 'name' });

    // ✨ FIX: Slug folgt dem Namen nur, wenn das Slug-Feld noch nicht manuell editiert wurde
    useEffect(() => {
        if (!editingGroup && !dirtyFields.slug && watchName) {
            setValue('slug', toSlug(watchName));
        }
    }, [watchName, editingGroup, dirtyFields.slug, setValue]);

    const onSubmit = async (data: GroupFormValues) => {
        const isPub = data.is_public === 'null' ? null : data.is_public === 'true';
        const pId = data.parent_id === '' ? null : data.parent_id;

        try {
            if (editingGroup) {
                await onUpdate(editingGroup.id, data.name, data.slug, isPub, pId, { is_free_download: data.is_free_download, is_editorial_only: data.is_editorial_only, is_hidden: data.is_hidden });
                showToast('success', 'Ordner erfolgreich aktualisiert.');
            } else {
                await onCreate(data.name, data.slug, isPub, pId, { is_free_download: data.is_free_download, is_editorial_only: data.is_editorial_only, is_hidden: data.is_hidden });
                showToast('success', 'Ordner erfolgreich erstellt.');
            }
            onClose();
        } catch (e: unknown) {
            showToast('error', (e as Error).message || 'Fehler beim Speichern');
        }
    };

    const handleDelete = async () => {
        if (!editingGroup) return;
        if (await confirm({ title: 'Meta-Galerie löschen?', message: 'ACHTUNG: Alle Unterordner werden dabei in die Root-Ebene verschoben!', confirmText: 'Löschen', confirmColor: 'error' })) {
            try {
                await onDelete(editingGroup.id);
                showToast('success', 'Ordner erfolgreich gelöscht.');
                onClose();
            } catch (e: unknown) {
                showToast('error', (e as Error).message || 'Fehler beim Löschen');
            }
        }
    };

    if (!isOpen) return null;

    return (
        <dialog className="modal modal-open z-[60]">
            <div className="modal-box relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-lg mb-4">{editingGroup ? 'Meta-Galerie bearbeiten' : 'Neue Meta-Galerie erstellen'}</h3>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div className="form-control w-full">
                            <label className="label"><span className="label-text font-bold">Name</span></label>
                            <input type="text" {...register('name')} className="input input-bordered w-full" />
                        </div>
                        <div className="form-control w-full">
                            <label className="label"><span className="label-text font-bold">URL Slug</span></label>
                            <input type="text" {...register('slug')}
                                   onChange={(e) => setValue('slug', toSlug(e.target.value), {shouldDirty: true})}
                                   className="input input-bordered w-full text-sm font-mono"
                            />
                        </div>
                    </div>

                    <div className="form-control w-full mb-4">
                        <label className="label"><span className="label-text font-bold">Sichtbarkeits-Vorgabe</span></label>
                        <select {...register('is_public')} className="select select-bordered w-full">
                            <option value="null">Keine Vorgabe (Unterordner entscheiden selbst)</option>
                            <option value="false">Privat erzwingen (Nur mit Link / Passwort)</option>
                            <option value="true">Öffentlich erzwingen (Für alle sichtbar)</option>
                        </select>
                    </div>

                    <div className="space-y-3 mb-4">
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-primary/20 w-full">
                            <input type="checkbox" {...register('is_free_download')} className="checkbox checkbox-primary" />
                            <div>
                                <span className="label-text font-bold block">Kostenlosen Download erlauben</span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1">Gäste können Bilder dieses Ordners direkt ohne Wasserzeichen herunterladen.</span>
                            </div>
                        </label>
                        
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-base-300 w-full">
                            <input type="checkbox" {...register('is_editorial_only')} className="checkbox checkbox-primary" />
                            <div>
                                <span className="label-text font-bold block">Nur für redaktionelle Nutzung (Shop)</span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1">Sperrt kommerzielle Lizenzen im Checkout für diesen Ordner.</span>
                            </div>
                        </label>

                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-base-300 w-full">
                            <input type="checkbox" {...register('is_hidden')} className="checkbox checkbox-primary" />
                            <div>
                                <span className="label-text font-bold block">Im Frontend verstecken</span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1">Wird nicht in Suchergebnissen oder Feeds gelistet.</span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control w-full mb-6">
                        <label className="label"><span className="label-text font-bold">Übergeordnete Meta-Galerie</span></label>
                        <select {...register('parent_id')} className="select select-bordered w-full">
                            <option value="">-- Keine --</option>
                            {(() => {
                                const result = [];
                                let skipDepth = -1;
                                for (const g of availableGroups) {
                                    if (editingGroup && g.id === editingGroup.id) {
                                        skipDepth = g.depth;
                                        continue;
                                    }
                                    if (skipDepth !== -1) {
                                        if (g.depth > skipDepth) continue;
                                        skipDepth = -1;
                                    }
                                    result.push(g);
                                }
                                return result.map(g => <option key={g.id} value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>);
                            })()}
                        </select>
                    </div>

                    <div className="modal-action col-span-full flex justify-between">
                        {editingGroup ? (
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