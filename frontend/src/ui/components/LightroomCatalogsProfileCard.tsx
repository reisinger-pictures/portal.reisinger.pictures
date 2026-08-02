import { useState } from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useLightroomCatalogs, LightroomCatalog } from '../../logic/useLightroomCatalogs';
import { usePermissions } from '../../logic/usePermissions';
import { useUI } from './UIContext';
import EditableTableRow from '../management/components/EditableTableRow';

interface CatalogRowProps {
    catalog: LightroomCatalog;
    onSave: (id: string, name: string) => Promise<void>;
    onDelete: (id: string) => void;
}

function CatalogRow({ catalog, onSave, onDelete }: CatalogRowProps) {
    const [isEditing, setIsEditing] = useState(false);
    const [name, setName] = useState(catalog.name);
    const { showToast } = useUI();

    const handleSave = async () => {
        try {
            await onSave(catalog.id, name.trim());
            showToast('success', t`Katalog aktualisiert`);
            setIsEditing(false);
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        }
    };

    return (
        <EditableTableRow
            isEditing={isEditing}
            onStartEdit={() => setIsEditing(true)}
            onCancel={() => setIsEditing(false)}
            onSave={handleSave}
            onDelete={() => onDelete(catalog.id)}
            renderView={() => (
                <td>
                    <div className="font-bold text-base">{catalog.name}</div>
                </td>
            )}
            renderEdit={() => (
                <td>
                    <input
                        type="text"
                        className="input input-bordered w-full"
                        value={name}
                        onChange={e => setName(e.target.value)}
                        required
                    />
                </td>
            )}
        />
    );
}

export default function LightroomCatalogsProfileCard() {
    const { isPhotographer, isSuperAdmin } = usePermissions();
    const { lightroomCatalogs, isLoading, create, update, remove } = useLightroomCatalogs();
    const { showToast, confirm } = useUI();
    const [newName, setNewName] = useState('');

    if (!isPhotographer && !isSuperAdmin) return null;

    const handleAdd = async () => {
        if (!newName.trim()) {
            showToast('error', t`Name ist erforderlich`);
            return;
        }
        try {
            await create(newName.trim());
            showToast('success', t`Katalog hinzugefügt`);
            setNewName('');
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        }
    };

    const handleDelete = async (id: string) => {
        if (await confirm({
            title: t`Katalog löschen?`,
            message: t`Möchtest du diesen Katalog wirklich löschen?`,
            confirmColor: 'error',
        })) {
            try {
                await remove(id);
                showToast('success', t`Katalog gelöscht`);
            } catch {
                showToast('error', t`Löschen fehlgeschlagen`);
            }
        }
    };

    if (isLoading) return <div className="p-8 text-center"><span className="loading loading-spinner text-primary"></span></div>;

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--folder-multiple-image text-primary text-3xl"></span>
                    <Trans>Deine Lightroom-Kataloge</Trans>
                </h2>
                <p className="text-sm opacity-70 mb-8 max-w-3xl">
                    <Trans>Verwalte deine Lightroom-Kataloge. Sie werden dir bei der Zuweisung in der Bildbearbeitung angeboten und sind nur für dich sichtbar.</Trans>
                </p>

                <div className="overflow-x-auto rounded-box border border-base-300 mb-4 max-h-96 overflow-y-auto">
                    <table className="table table-zebra w-full">
                        <thead className="bg-base-200 sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th><Trans>Name</Trans></th>
                                <th className="text-right w-36"><Trans>Aktionen</Trans></th>
                            </tr>
                        </thead>
                        <tbody>
                            {lightroomCatalogs?.map(c => (
                                <CatalogRow key={c.id} catalog={c} onSave={update} onDelete={handleDelete} />
                            ))}
                            {(!lightroomCatalogs || lightroomCatalogs.length === 0) && (
                                <tr>
                                    <td colSpan={2} className="py-10">
                                        <div className="flex flex-col items-center gap-2 opacity-60">
                                            <span className="iconify mdi--archive-off text-3xl"></span>
                                            <span className="text-sm font-medium"><Trans>Noch keine Lightroom-Kataloge angelegt.</Trans></span>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="bg-base-200/50 p-4 rounded-box border border-base-300 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="form-control w-full">
                        <label className="label py-1"><span className="label-text text-sm font-bold"><Trans>Neuer Katalog</Trans></span></label>
                        <input
                            type="text"
                            placeholder={t`z.B. 2026-08`}
                            value={newName}
                            onChange={e => setNewName(e.target.value)}
                            className="input input-bordered w-full"
                            required
                        />
                    </div>
                    <div className="form-control w-full">
                        <label className="label py-1"><span className="label-text text-sm font-bold">&zwnj;</span></label>
                        <button onClick={handleAdd} className="btn btn-primary w-full"><span className="iconify mdi--plus"></span> <Trans>Hinzufügen</Trans></button>
                    </div>
                </div>
            </div>
        </div>
    );
}
