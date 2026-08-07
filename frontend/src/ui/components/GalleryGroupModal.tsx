import { useEffect } from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { GalleryGroup, FlatGroup, GalleryGroupExtraOpts } from '../../logic/useGalleries';
import { Org } from '../../logic/useOrgs';
import { useUI } from './UIContext';
import { useForm } from 'react-hook-form';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toSlug } from '../../logic/utils';
import CheckboxGroup from '../components/CheckboxGroup';
import ModalDialogShell from './ModalDialogShell';

const groupSchema = z.object({
    name: z.string().min(1, t`Name ist erforderlich`),
    slug: z.string(),
    is_public: z.enum(['null', 'true', 'false']),
    parent_id: z.string(),
    is_free_download: z.boolean().optional(),
    is_editorial_only: z.boolean().optional(),
    is_hidden: z.boolean().optional(),
    org_id: z.string().optional()
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
    "use no memo";
    const { showToast, confirm } = useUI();
    const { data: orgs, isLoading } = useSWR<Org[]>('/api/management/orgs', fetcher);

    const { register, handleSubmit, reset, setValue, formState: { isSubmitting, dirtyFields } } = useForm<GroupFormValues>({
        resolver: zodResolver(groupSchema),
        defaultValues: { name: '', slug: '', is_public: 'null', parent_id: '', is_free_download: false, is_editorial_only: false, is_hidden: false }
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingGroup?.name || '',
                org_id: editingGroup?.org_id || '',
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
                showToast('success', t`Ordner erfolgreich aktualisiert.`);
            } else {
                await onCreate(data.name, data.slug, isPub, pId, { is_free_download: data.is_free_download, is_editorial_only: data.is_editorial_only, is_hidden: data.is_hidden });
                showToast('success', t`Ordner erfolgreich erstellt.`);
            }
            onClose();
        } catch (e: unknown) {
            showToast('error', (e as Error).message || t`Fehler beim Speichern`);
        }
    };

    const handleDelete = async () => {
        if (!editingGroup) return;
        if (await confirm({ title: t`Meta-Galerie löschen?`, message: t`ACHTUNG: Alle Unterordner werden dabei in die Root-Ebene verschoben!`, confirmText: t`Löschen`, confirmColor: 'error' })) {
            try {
                await onDelete(editingGroup.id);
                showToast('success', t`Ordner erfolgreich gelöscht.`);
                onClose();
            } catch (e: unknown) {
                showToast('error', (e as Error).message || t`Fehler beim Löschen`);
            }
        }
    };

    const parentGroupOptions = (() => {
        const result: FlatGroup[] = [];
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
        return result;
    })();

    if (!isOpen) return null;
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <ModalDialogShell
            title={editingGroup ? <Trans>Meta-Galerie bearbeiten</Trans> : <Trans>Neue Meta-Galerie erstellen</Trans>}
            onClose={onClose}
            onDelete={handleDelete}
            editing={!!editingGroup}
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
        >
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div className="form-control w-full">
                    <label className="label"><span className="label-text font-bold"><Trans>Name</Trans></span></label>
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
                    <label className="label"><span className="label-text font-bold"><Trans>URL Slug</Trans></span></label>
                    <input type="text" {...register('slug')}
                           onChange={(e) => setValue('slug', toSlug(e.target.value), {shouldDirty: true})}
                           className="input input-bordered w-full text-sm font-mono"
                    />
                </div>
            </div>

            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold"><Trans>Sichtbarkeits-Vorgabe</Trans></span></label>
                <select {...register('is_public')} className="select select-bordered w-full">
                    <option value="null"><Trans>Keine Vorgabe (Unterordner entscheiden selbst)</Trans></option>
                    <option value="false"><Trans>Privat erzwingen (Nur mit Link / Passwort)</Trans></option>
                    <option value="true"><Trans>Öffentlich erzwingen (Für alle sichtbar)</Trans></option>
                </select>
            </div>

            <div className="space-y-3 mb-4">
                <CheckboxGroup items={[
                    { name: 'is_free_download', label: t`Kostenlosen Download erlauben`, description: t`Gäste können Bilder dieses Ordners direkt ohne Wasserzeichen herunterladen.` },
                    { name: 'is_editorial_only', label: t`Nur für redaktionelle Nutzung (Shop)`, description: t`Sperrt kommerzielle Lizenzen im Checkout für diesen Ordner.` },
                    { name: 'is_hidden', label: t`Im Frontend verstecken`, description: t`Wird nicht in Suchergebnissen oder Feeds gelistet.` },
                ]} register={register} />
            </div>

            
            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold"><Trans>Zugeordnete Organisation (Verschieben)</Trans></span></label>
                <select {...register('org_id')} className="select select-bordered w-full">
                    <option value="">-- <Trans>Keine spezifische Organisation</Trans> --</option>
                    {orgs?.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
            </div>

            <div className="form-control w-full mb-6">
                <label className="label"><span className="label-text font-bold"><Trans>Übergeordnete Meta-Galerie</Trans></span></label>
                <select {...register('parent_id')} className="select select-bordered w-full">
                    <option value="">-- <Trans>Keine</Trans> --</option>
                    {parentGroupOptions.map(g => <option key={g.id} value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                </select>
            </div>
        </ModalDialogShell>
    );
}