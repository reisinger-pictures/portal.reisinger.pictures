import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';
import { PhotoJob, PhotoJobInput } from '../../../logic/useProductionBoard';
import { useUsers } from '../../../logic/useUsers';
import { useProtectedGalleries } from '../../../logic/useGalleries';
import { useLightroomCatalogs } from '../../../logic/useLightroomCatalogs';
import type { Gallery } from '../../../api';
import type { GalleryGroup } from '../../../logic/useGalleries';

function collectGalleries(groups: { galleries?: Gallery[]; children?: GalleryGroup[]; }[], acc: Gallery[]): Gallery[] {
    for (const g of groups) {
        if (g.galleries) acc.push(...g.galleries);
        if (g.children) collectGalleries(g.children, acc);
    }
    return acc;
}
const countMessage = t`Bitte eine gültige Anzahl eingeben`;

export interface BoardStatusOption { value: string; label: string; }

const photoJobSchema = z.object({
    title: z.string().min(1, t`Titel ist erforderlich`),
    lightroom_catalog: z.string().optional(),
    total_count: z.string().optional().refine(
        (val) => val === undefined || val === '' || (Number.isInteger(Number(val)) && Number(val) >= 0),
        { message: countMessage }
    ),
    selected_count: z.string().optional().refine(
        (val) => val === undefined || val === '' || (Number.isInteger(Number(val)) && Number(val) >= 0),
        { message: countMessage }
    ),
    target_gallery_id: z.string().optional(),
    status: z.string().optional(),
    is_private: z.boolean().optional(),
    assignee_id: z.string().optional(),
});

type PhotoJobFormValues = z.infer<typeof photoJobSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    defaultStatus: string;
    statusOptions: BoardStatusOption[];
    editing?: PhotoJob | null;
    onSave: (payload: PhotoJobInput & { status?: string }) => Promise<void>;
}

export default function PhotoJobModal({ isOpen, onClose, editing, onSave, defaultStatus, statusOptions }: Props) {
    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<PhotoJobFormValues>({
        resolver: zodResolver(photoJobSchema),
        defaultValues: {
            title: '',
            lightroom_catalog: '',
            total_count: '',
            selected_count: '',
            target_gallery_id: '',
            status: '',
            is_private: false,
            assignee_id: '',
        },
    });
    const { showToast } = useUI();
    const { users } = useUsers();
    const { tree } = useProtectedGalleries();
    const { lightroomCatalogs, error: catalogsError } = useLightroomCatalogs();
    const galleries = tree ? [...(tree.root_galleries ?? []), ...collectGalleries(tree.groups ?? [], [])] : undefined;
    const catalogOptions = catalogsError ? [] : (lightroomCatalogs ?? []);

    useEffect(() => {
        if (isOpen) {
            reset({
                title: editing?.title ?? '',
                lightroom_catalog: editing?.lightroom_catalog ?? '',
                total_count: editing?.total_count != null ? String(editing.total_count) : '',
                selected_count: editing?.selected_count != null ? String(editing.selected_count) : '',
                target_gallery_id: editing?.target_gallery_id ?? '',
                status: editing?.status ?? defaultStatus ?? '',
                is_private: editing?.is_private ?? false,
                assignee_id: editing?.assignee?.id ?? '',
            });
        }
    }, [isOpen, editing, defaultStatus, reset]);

    if (!isOpen) return null;

    const onSubmit = async (data: PhotoJobFormValues) => {
        const total = data.total_count === undefined || data.total_count === '' ? undefined : Number(data.total_count);
        const selected = data.selected_count === undefined || data.selected_count === '' ? undefined : Number(data.selected_count);
        const input: PhotoJobInput = {
            title: data.title,
            lightroom_catalog: data.lightroom_catalog || null,
            total_count: total,
            selected_count: selected,
            target_gallery_id: data.target_gallery_id || null,
            is_private: data.is_private ?? false,
        };
        if (data.assignee_id) {
            input.assignee_id = data.assignee_id;
        }
        const payload: PhotoJobInput & { status?: string } = { ...input, status: data.status || undefined };
        try {
            await onSave(payload);
            onClose();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Speichern fehlgeschlagen`);
        }
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-2xl">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6">
                    {editing ? <Trans>Auftrag bearbeiten</Trans> : <Trans>Neuen Auftrag anlegen</Trans>}
                </h3>
                <form onSubmit={handleSubmit(onSubmit)} noValidate>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold"><Trans>Titel</Trans></span></label>
                            <input required type="text" {...register('title')} className={`input input-bordered ${errors.title ? 'input-error' : ''}`} />
                            {errors.title && <span className="text-error text-xs mt-1">{errors.title.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Lightroom-Katalog</Trans></span></label>
                            <select {...register('lightroom_catalog')} className="select select-bordered">
                                <option value=""><Trans>Kein Katalog</Trans></option>
                                {catalogOptions.map(c => <option key={c.id} value={c.name}>{c.name}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Ziel-Galerie</Trans></span></label>
                            <select {...register('target_gallery_id')} className="select select-bordered">
                                <option value=""><Trans>Keine</Trans></option>
                                {galleries?.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Bilder gesamt</Trans></span></label>
                            <input type="number" min="0" step="1" {...register('total_count')} className={`input input-bordered ${errors.total_count ? 'input-error' : ''}`} />
                            {errors.total_count && <span className="text-error text-xs mt-1">{errors.total_count.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Bilder selektiert</Trans></span></label>
                            <input type="number" min="0" step="1" {...register('selected_count')} className={`input input-bordered ${errors.selected_count ? 'input-error' : ''}`} />
                            {errors.selected_count && <span className="text-error text-xs mt-1">{errors.selected_count.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Zuständig</Trans></span></label>
                            <select {...register('assignee_id')} className="select select-bordered">
                                <option value=""><Trans>Keine Zuweisung</Trans></option>
                                {users?.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Status</Trans></span></label>
                            <select {...register('status')} className="select select-bordered">
                                {statusOptions.map(opt => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" className="checkbox" {...register('is_private')} />
                                <span className="label-text font-bold"><Trans>Privat</Trans></span>
                            </label>
                        </div>
                    </div>
                    <div className="modal-action">
                        <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}