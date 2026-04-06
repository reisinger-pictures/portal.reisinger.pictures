import { useEffect, useState } from 'react';
import { usePhoto, PhotoVersion } from '../../logic/usePhoto';
import { useUI } from './UIContext';

interface Props {
    photoId: string;
    isOpen: boolean;
    onClose: () => void;
    onReverted: () => void;
}

export default function PhotoHistoryModal({ photoId, isOpen, onClose, onReverted }: Props) {
    const [history, setHistory] = useState<PhotoVersion[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const { getVersions, revertMetadata } = usePhoto();
    const { showToast, confirm } = useUI();
    const [prevIsOpen, setPrevIsOpen] = useState(false);

    if (isOpen !== prevIsOpen) {
        setPrevIsOpen(isOpen);
        if (isOpen && photoId) {
            setLoadingHistory(true);
        }
    }

    useEffect(() => {
        let isMounted = true;
        if (isOpen && photoId) {
            getVersions(photoId)
                .then(data => { if(isMounted) setHistory(data); })
                .catch(() => { if(isMounted) showToast('error', 'Historie konnte nicht geladen werden.'); })
                .finally(() => { if(isMounted) setLoadingHistory(false); });
        }
        return () => { isMounted = false; };
    }, [isOpen, photoId, getVersions, showToast]);

    const handleRevert = async (versionId: string) => {
        if (!(await confirm({ title: 'Version wiederherstellen?', message: 'Möchtest du diese Metadaten-Version wirklich wiederherstellen? Dies überschreibt den aktuellen Stand.', confirmText: 'Wiederherstellen', confirmColor: 'warning' }))) return;
        try {
            await revertMetadata(photoId, versionId);
            onClose();
            onReverted();
            showToast('success', 'Metadaten wiederhergestellt.');
        } catch {
            showToast('error', 'Fehler beim Wiederherstellen der Version.');
        }
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-4xl relative">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--history text-primary"></span> Änderungshistorie (Vor-Zustände)
                </h3>
                <p className="text-sm opacity-70 mb-4">Hier werden die ursprünglichen Metadaten gespeichert, <strong>bevor</strong> ein Kunde eine Änderung vorgenommen hat.</p>

                {loadingHistory ? (
                    <div className="flex justify-center p-8"><span className="loading loading-spinner"></span></div>
                ) : (
                    <div className="overflow-x-auto border border-base-300 rounded-box">
                        <table className="table table-zebra w-full text-sm">
                            <thead className="bg-base-200">
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>Geändert von</th>
                                <th>Titel / Beschreibung</th>
                                <th className="text-right">Aktion</th>
                            </tr>
                            </thead>
                            <tbody>
                            {history.map(ver => (
                                <tr key={ver.id}>
                                    <td className="whitespace-nowrap">{new Date(ver.created_at).toLocaleString('de-DE')}</td>
                                    <td>{ver.user?.name || 'Unbekannt'}</td>
                                    <td>
                                        <div className="max-w-[300px] truncate" title={ver.title}>{ver.title || '-'}</div>
                                        <div className="max-w-[300px] truncate opacity-70" title={ver.description}>{ver.description || '-'}</div>
                                    </td>
                                    <td className="text-right">
                                        <button onClick={() => handleRevert(ver.id)} className="btn btn-xs btn-outline btn-warning">
                                            Wiederherstellen
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {history.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-center py-6 opacity-50">Keine vorherigen Versionen gefunden.</td>
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