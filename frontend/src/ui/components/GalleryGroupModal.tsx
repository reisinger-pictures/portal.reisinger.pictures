import { useEffect } from 'react';
import { GalleryGroup, FlatGroup, GalleryGroupExtraOpts } from '../../logic/useGalleries';
import { Tenant } from '../../logic/useTenants';
import { useUI } from './UIContext';
import { useForm } from 'react-hook-form';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toSlug } from '../../logic/utils';
import CheckboxGroup from '../components/CheckboxGroup';

const groupSchema = z.object({
    name: z.string().min(1, 'Name ist erforderlich'),
    slug: z.string(),
    is_public: z.enum(['null', 'true', 'false']),
    parent_id: z.string(),
    is_free_download: z.boolean().optional(),
    is_editorial_only: z.boolean().optional(),
    is_hidden: z.boolean().optional(),
    tenant_id: z.string().optional()
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

export default function GalleryGroupModal({ isOpen, onClose, availableGroups, editingGroup, defaultParentId, onCreate, onUpdate, onDelete }: Props) {
    const { showToast, confirm } = useUI();
    const { data: tenants, isLoading } = useSWR<Tenant[]>('/api/management/tenants', fetcher);

    const { register, handleSubmit, reset, setValue, formState: { isSubmitting, dirtyFields } } = useForm<GroupFormValues>({
        resolver: zodResolver(groupSchema),
        defaultValues: { name: '', slug: '', is_public: 'null', parent_id: '', is_free_download: false, is_editorial_only: false, is_hidden: false }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGroup?.name || '',
                tenant_id: editingGroup?.tenant_id || '',
                slug: editingGroup?.slug || '',
                is_free_download: !!editingGroup?.is_free_download,
                is_editorial_only: !!editingGroup?.is_editorial_only,
                is_hidden: !!editingGroup?.is_hidden,
                is_public: editingGroup?.is_public === null || editingGroup?.is_public === undefined ? 'null' : (editingGroup.is_public ? 'true' : 'false'),
                parent_id: editingGroup?.parent_id || defaultParentId || ''
            });
        }
    }, [isOpen, editingGroup, reset, defaultParentId]);

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
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <dialog className="modal modal-open z-[60]">
            <div className="modal-box relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-lg mb-4">{editingGroup ? 'Meta-Galerie bearbeiten' : 'Neue Meta-Galerie erstellen'}</h3>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div className="form-control w-full">
                            <label className="label"><span className="label-text font-bold">Name</span></label>
                            <input type="text" {...register('name')}
                                   onChange={(e) => {
                                       setValue('name', e.target.value, { shouldDirty: true });
                                       if (!editingGroup && !dirtyFields.slug && e.target.value) {
                                           setValue('slug', toSlug(e.target.value));
                                       }
                                   }}
                                   className="input input-bordered w-full"
                            />
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
                        <CheckboxGroup items={[
                            { name: 'is_free_download', label: 'Kostenlosen Download erlauben', description: 'Gäste können Bilder dieses Ordners direkt ohne Wasserzeichen herunterladen.' },
                            { name: 'is_editorial_only', label: 'Nur für redaktionelle Nutzung (Shop)', description: 'Sperrt kommerzielle Lizenzen im Checkout für diesen Ordner.' },
                            { name: 'is_hidden', label: 'Im Frontend verstecken', description: 'Wird nicht in Suchergebnissen oder Feeds gelistet.' },
                        ]} register={register} />
                    </div>

                    
                    <div className="form-control w-full mb-4">
                        <label className="label"><span className="label-text font-bold">Zugeordnete Organisation (Verschieben)</span></label>
                        <select {...register('tenant_id')} className="select select-bordered w-full">
                            <option value="">-- Keine spezifische Organisation --</option>
                            {tenants?.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </select>
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