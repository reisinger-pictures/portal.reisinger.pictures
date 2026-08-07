import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useReducer, useRef } from 'react';
import { usePhoto, PhotoVersion } from '../../logic/usePhoto';
import { useUI } from './UIContext';

interface Props {
    photoId: string;
    isOpen: boolean;
    onClose: () => void;
    onReverted: () => void;
}

type HistoryState = { status: 'loading' } | { status: 'loaded'; versions: PhotoVersion[] };

function historyReducer(_state: HistoryState, action: { type: 'LOAD' } | { type: 'LOADED'; versions: PhotoVersion[] }): HistoryState {
    switch (action.type) {
        case 'LOAD': return { status: 'loading' };
        case 'LOADED': return { status: 'loaded', versions: action.versions };
    }
}

export default function PhotoHistoryModal({ photoId, isOpen, onClose, onReverted }: Props) {
    const [state, dispatch] = useReducer(historyReducer, { status: 'loading' });
    const { getVersions, revertMetadata } = usePhoto();
    const { showToast, confirm } = useUI();

    const getVersionsRef = useRef(getVersions);
    useEffect(() => {
        getVersionsRef.current = getVersions;
    }, [getVersions]);

    useEffect(() => {
        let isMounted = true;
        if (isOpen && photoId) {
            dispatch({ type: 'LOAD' });
            getVersionsRef.current(photoId)
                .then(data => { if(isMounted) dispatch({ type: 'LOADED', versions: data }); })
                .catch(() => { if(isMounted) { showToast('error', t`Historie konnte nicht geladen werden.`); dispatch({ type: 'LOADED', versions: [] }); } });
        }
        return () => { isMounted = false; };
    }, [isOpen, photoId, showToast]);

    const handleRevert = async (versionId: string) => {
        if (!(await confirm({ title: t`Version wiederherstellen?`, message: t`Möchtest du diese Metadaten-Version wirklich wiederherstellen? Dies überschreibt den aktuellen Stand.`, confirmText: t`Wiederherstellen`, confirmColor: 'warning' }))) return;
        try {
            await revertMetadata(photoId, versionId);
            onClose();
            onReverted();
            showToast('success', t`Metadaten wiederhergestellt.`);
        } catch {
            showToast('error', t`Fehler beim Wiederherstellen der Version.`);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-4xl relative">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--history text-primary"></span> <Trans>Änderungshistorie (Vor-Zustände)</Trans>
                </h3>
                <p className="text-sm opacity-70 mb-4"><Trans>Hier werden die ursprünglichen Metadaten gespeichert, <strong>bevor</strong> ein Kunde eine Änderung vorgenommen hat.</Trans></p>

                {state.status === 'loading' ? (
                    <div className="flex justify-center p-8"><span className="loading loading-spinner"></span></div>
                ) : (
                    <div className="overflow-x-auto border border-base-300 rounded-box">
                        <table className="table table-zebra w-full text-sm">
                            <thead className="bg-base-200">
                            <tr>
                                <th><Trans>Zeitpunkt</Trans></th>
                                <th><Trans>Geändert von</Trans></th>
                                <th><Trans>Titel / Beschreibung</Trans></th>
                                <th className="text-right"><Trans>Aktion</Trans></th>
                            </tr>
                            </thead>
                            <tbody>
                            {state.versions.map(ver => (
                                <tr key={ver.id}>
                                    <td className="whitespace-nowrap">{new Date(ver.created_at).toLocaleString('de-DE')}</td>
                                    <td>{ver.user?.name || t`Unbekannt`}</td>
                                    <td>
                                        <div className="max-w-xs truncate" title={ver.title}>{ver.title || '-'}</div>
                                        <div className="max-w-xs truncate opacity-70" title={ver.description}>{ver.description || '-'}</div>
                                    </td>
                                    <td className="text-right">
                                        <button onClick={() => handleRevert(ver.id)} className="btn btn-xs btn-outline btn-warning">
                                            <Trans>Wiederherstellen</Trans>
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {state.versions.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-center py-6 opacity-50"><Trans>Keine vorherigen Versionen gefunden.</Trans></td>
                                </tr>
                            )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}