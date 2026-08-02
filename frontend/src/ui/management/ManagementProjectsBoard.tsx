import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { useProjectsBoard, Project, ProjectInput } from '../../logic/useProjectsBoard';
import { usePermissions } from '../../logic/usePermissions';
import { useUI } from '../components/UIContext';
import { formatMoney } from '../../logic/utils';
import KanbanBoard, { KanbanColumnDef } from '../components/KanbanBoard';
import ErrorMessage from '../components/ErrorMessage';
import ProjectModal from './components/ProjectModal';

const columns: KanbanColumnDef[] = [
    { status: 'anfrage', label: t`Anfrage` },
    { status: 'angebot', label: t`Angebot` },
    { status: 'beauftragt', label: t`Beauftragt` },
    { status: 'rechnung', label: t`Rechnung` },
    { status: 'bezahlt', label: t`Bezahlt` },
];

function paymentBadge(status: string) {
    switch (status) {
        case 'paid':
            return <span className="badge badge-success badge-sm text-white"><Trans>Bezahlt</Trans></span>;
        case 'partly_paid':
            return <span className="badge badge-warning badge-sm"><Trans>Teilbezahlt</Trans></span>;
        default:
            return <span className="badge badge-ghost badge-sm"><Trans>Offen</Trans></span>;
    }
}

export default function ManagementProjectsBoard() {
    const { projects, isLoading, error, create, update, move, remove } = useProjectsBoard();
    const { canAccessProjectsBoard } = usePermissions();
    const { showToast, confirm } = useUI();

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Project | null>(null);
    const [defaultStatus, setDefaultStatus] = useState(columns[0].status);

    if (!canAccessProjectsBoard) {
        return <div className="p-10"><ErrorMessage message={t`Kein Zugriff auf dieses Board.`} /></div>;
    }
    if (isLoading) {
        return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    }
    if (error) {
        return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden der Projekte.`} /></div>;
    }

    const items = projects ?? [];

    const openNew = (status: string) => {
        setEditing(null);
        setDefaultStatus(status);
        setModalOpen(true);
    };

    const openEdit = (item: Project) => {
        setEditing(item);
        setDefaultStatus(item.status);
        setModalOpen(true);
    };

    const handleSave = async (input: ProjectInput) => {
        if (editing) {
            await update(editing.id, input);
            showToast('success', t`Projekt aktualisiert`);
        } else {
            await create(input);
            showToast('success', t`Projekt angelegt`);
        }
    };

    const handleDelete = async (item: Project) => {
        const name = item.client_name;
        if (!(await confirm({
            title: t`Projekt löschen?`,
            message: t`Möchtest du das Projekt "${name}" wirklich löschen?`,
            confirmText: t`Löschen`,
            confirmColor: 'error',
        }))) return;
        try {
            await remove(item.id);
            showToast('success', t`Projekt gelöscht`);
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
            <button type="button" className="btn btn-circle btn-xs btn-ghost" title={t`Neues Projekt`} onClick={() => openNew(status)}>
                <span className="iconify mdi--plus"></span>
            </button>
        </div>
    );

    const renderCard = (item: Project) => (
        <div className="card card-body p-3 bg-base-100 shadow-sm border border-base-300 rounded-lg hover:border-primary">
            <div className="flex items-start justify-between gap-2">
                <div className="font-semibold leading-tight">{item.client_name}</div>
                <button type="button" className="btn btn-circle btn-xs btn-ghost" onClick={() => handleDelete(item)}>
                    <span className="iconify mdi--trash-can-outline text-error"></span>
                </button>
            </div>
            {item.email && <div className="text-xs opacity-60 break-all">{item.email}</div>}
            {item.phone && <div className="text-xs opacity-60">{item.phone}</div>}
            {item.package && <div className="text-xs opacity-70">{item.package}</div>}
            <div className="flex flex-wrap items-center gap-1 mt-1">
                {item.price_cents > 0 && <span className="font-bold text-sm">{formatMoney(item.price_cents)}</span>}
                {paymentBadge(item.payment_status)}
            </div>
            <div className="flex items-center gap-1 text-xs opacity-60 mt-1">
                <span className="iconify mdi--account"></span>
                {item.assignee ? item.assignee.name : (item.owner?.name ?? t`Unbekannt`)}
            </div>
            <button type="button" onClick={() => openEdit(item)} className="mt-2 btn btn-xs btn-outline"><Trans>Details</Trans></button>
        </div>
    );

    return (
        <>
            <KanbanBoard
                title={t`Projekte`}
                icon="mdi--briefcase-outline"
                columns={columns}
                items={items}
                onMove={(id, status, position) => {
                    move(id, status, position).catch((err: unknown) => {
                        showToast('error', err instanceof Error ? err.message : t`Verschieben fehlgeschlagen`);
                    });
                }}
                renderColumnHeader={renderColumnHeader}
                renderCard={renderCard}
            />
            <ProjectModal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                defaultStatus={defaultStatus}
                editing={editing}
                onSave={handleSave}
            />
        </>
    );
}