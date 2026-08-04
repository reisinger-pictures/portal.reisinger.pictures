import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { useProductionBoard, PhotoJob, PhotoJobInput } from '../../logic/useProductionBoard';
import { useIsDesktop } from '../../logic/useMediaQuery';
import { usePermissions } from '../../logic/usePermissions';
import { useUI } from '../components/UIContext';
import KanbanBoard, { KanbanColumnDef } from '../components/KanbanBoard';
import ErrorMessage from '../components/ErrorMessage';
import PhotoJobModal from './components/PhotoJobModal';

const columns: KanbanColumnDef[] = [
    { status: 'importiert', label: t`Importiert` },
    { status: 'culling', label: t`Culling` },
    { status: 'bearbeitung', label: t`Bearbeitung` },
    { status: 'exportiert', label: t`Exportiert` },
    { status: 'abgebrochen', label: t`Abgebrochen` },
];

interface PhotographerProductionBoardProps {
    embedded?: boolean;
}

export default function PhotographerProductionBoard({ embedded = false }: PhotographerProductionBoardProps) {
    const { photoJobs, isLoading, error, create, update, move, remove } = useProductionBoard();
    const { canAccessProductionBoard, isSuperAdmin } = usePermissions();
    const isDesktop = useIsDesktop();
    const disallowDrag = !isSuperAdmin || !isDesktop;
    const { showToast, confirm } = useUI();

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<PhotoJob | null>(null);
    const [defaultStatus, setDefaultStatus] = useState(columns[0].status);

    if (!canAccessProductionBoard) {
        return <div className="p-10"><ErrorMessage message={t`Kein Zugriff auf dieses Board.`} /></div>;
    }
    if (isLoading) {
        return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    }
    if (error) {
        return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden der Aufträge.`} /></div>;
    }

    const items = photoJobs ?? [];

    const openNew = (status: string) => {
        setEditing(null);
        setDefaultStatus(status);
        setModalOpen(true);
    };

    const openEdit = (item: PhotoJob) => {
        setEditing(item);
        setDefaultStatus(item.status);
        setModalOpen(true);
    };

    const handleSave = async (input: PhotoJobInput) => {
        if (editing) {
            await update(editing.id, input);
            showToast('success', t`Auftrag aktualisiert`);
        } else {
            await create(input);
            showToast('success', t`Auftrag angelegt`);
        }
    };

    const handleDelete = async (item: PhotoJob) => {
        const title = item.title;
        if (!(await confirm({
            title: t`Auftrag löschen?`,
            message: t`Möchtest du den Auftrag "${title}" wirklich löschen?`,
            confirmText: t`Löschen`,
            confirmColor: 'error',
        }))) return;
        try {
            await remove(item.id);
            showToast('success', t`Auftrag gelöscht`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Löschen fehlgeschlagen`);
        }
    };

    const renderColumnHeader = (status: string, label: string) => (
        <div className="flex items-center justify-between px-3 py-2 bg-base-300/60 border-b border-base-300">
            <div className="flex items-center gap-2 font-bold text-sm">
                {label}
                <span className="badge badge-ghost badge-sm">{items.filter(i => i.status === status).length}</span>
            </div>
            <button type="button" className="btn btn-primary btn-sm btn-circle border-0" title={t`Neuer Auftrag`} onClick={() => openNew(status)}>
                <span className="iconify mdi--plus"></span>
            </button>
        </div>
    );

    const renderCard = (item: PhotoJob) => (
        <div className={`card card-body p-3 bg-base-100 shadow-sm border border-base-300 rounded-lg hover:border-primary ${item.status === 'abgebrochen' ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="font-semibold leading-tight">{item.title}</div>
                <button type="button" className="btn btn-circle btn-xs btn-ghost" onClick={() => handleDelete(item)}>
                    <span className="iconify mdi--trash-can-outline text-error"></span>
                </button>
            </div>
            {(item.lightroom_catalog_is_mine === true || isSuperAdmin) && item.lightroom_catalog && (
                <div className="flex flex-wrap items-center gap-1 mt-1">
                    <span className="badge badge-ghost">{item.lightroom_catalog}</span>
                </div>
            )}
            {(item.total_count > 0 || item.selected_count > 0) && (
                <div className="text-xs text-base-content/70">
                    {item.selected_count} / {item.total_count} {t`Bilder`}
                </div>
            )}
            {item.notes && (
                <div className="flex items-start gap-1 text-xs text-base-content/70 mt-1 min-w-0">
                    <span className="iconify mdi--note-text-outline shrink-0"></span>
                    <span className="tooltip line-clamp-2 min-w-0" data-tip={item.notes}>{item.notes}</span>
                </div>
            )}
            <div className="flex items-center mt-1">
                <span className="badge badge-outline gap-1">
                    <span className="iconify mdi--account"></span>
                    {item.assignee ? item.assignee.name : (item.owner?.name ?? t`Unbekannt`)}
                </span>
            </div>
            <select
                className="select select-xs select-bordered mt-2 w-full"
                aria-label={t`Status ändern`}
                value={item.status}
                onChange={(e) => {
                    const newStatus = e.target.value;
                    const endPosition = items.filter(i => i.status === newStatus && i.id !== item.id).length;
                    move(item.id, newStatus, endPosition).catch((err: unknown) => {
                        showToast('error', err instanceof Error ? err.message : t`Verschieben fehlgeschlagen`);
                    });
                }}
            >
                {columns.map(col => (
                    <option key={col.status} value={col.status}>{col.label}</option>
                ))}
            </select>
            <button type="button" onClick={() => openEdit(item)} className="mt-2 btn btn-xs btn-outline"><Trans>Details</Trans></button>
        </div>
    );

    return (
        <>
            <KanbanBoard
                title={t`Bildbearbeitung`}
                icon="mdi--image-edit"
                columns={columns}
                items={items}
                onMove={(id, status, position) => {
                    move(id, status, position).catch((err: unknown) => {
                        showToast('error', err instanceof Error ? err.message : t`Verschieben fehlgeschlagen`);
                    });
                }}
                renderColumnHeader={renderColumnHeader}
                renderCard={renderCard}
                disallowDrag={disallowDrag}
                embedded={embedded}
            />
            <PhotoJobModal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                defaultStatus={defaultStatus}
                statusOptions={columns.map(c => ({ value: c.status, label: c.label }))}
                editing={editing}
                onSave={handleSave}
            />
        </>
    );
}