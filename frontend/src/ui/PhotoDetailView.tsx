import {useState, useEffect} from 'react';
import {useNavigate, useParams} from 'react-router-dom';
import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from '../logic/useGallery';
import {useAuth} from '../logic/useAuth';
import {usePhoto, PhotoVersion} from '../logic/usePhoto';
import PageLayout from './components/PageLayout';
import IptcMetadataEditor, { IptcData } from './components/IptcMetadataEditor';
import { useUI } from './components/UIContext';

interface Breadcrumb {
    name: string;
    type: 'group' | 'gallery';
    full_path?: string;
}

interface PhotoContextData {
    photo: Photo;
    breadcrumbs: Breadcrumb[];
    downloads_count: number;
}

export default function PhotoDetailView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {user} = useAuth();
    const {updateMetadata, deletePhoto, getVersions, revertMetadata} = usePhoto();
    const { showToast, confirm } = useUI();

    const {data, error, isLoading, mutate} = useSWR<PhotoContextData>(
        id ? '/api/photos/' + id + '/context' : null, fetcher
    );

    const [iptcData, setIptcData] = useState<IptcData>({});
    const [saving, setSaving] = useState(false);

    // History Modal State
    const [isHistoryOpen, setIsHistoryOpen] = useState(false);
    const [history, setHistory] = useState<PhotoVersion[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);

    // SWR Dependency Fix: Formular nur aktualisieren, wenn sich die DB-Werte WIRKLICH ändern
    const photoHash = data?.photo ? JSON.stringify([
        data.photo.title, data.photo.description, data.photo.artist,
        data.photo.headline, data.photo.keywords, data.photo.location,
        data.photo.city, data.photo.state, data.photo.country, data.photo.iso_country
    ]) : '';

    useEffect(() => {
        if (data?.photo) {
            setIptcData({
                title: data.photo.title || '',
                description: data.photo.description || '',
                artist: data.photo.artist || '',
                headline: data.photo.headline || '',
                keywords: data.photo.keywords || '',
                location: data.photo.location || '',
                city: data.photo.city || '',
                state: data.photo.state || '',
                country: data.photo.country || '',
                iso_country: data.photo.iso_country || ''
            });
        }
    }, [photoHash]);

    if (isLoading) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div>
    </PageLayout>;
    if (error || !data) return <PageLayout>
        <div className="p-8 text-center text-error">Foto konnte nicht geladen werden oder keine Berechtigung.</div>
    </PageLayout>;

    const {photo, breadcrumbs} = data;
    const isPhotogOrAdmin = user?.is_admin || user?.is_photographer;
    const canEdit = isPhotogOrAdmin || user?.can_edit_metadata;

    const handleSaveMeta = async () => {
        setSaving(true);
        try {
            await updateMetadata(photo.id, iptcData);
            mutate();
            showToast('success', 'Metadaten gespeichert');
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setSaving(false);
    };

    const handleDelete = async () => {
        if (!(await confirm({ title: 'Bild löschen?', message: 'Möchtest du dieses Bild wirklich endgültig löschen?', confirmText: 'Löschen', confirmColor: 'error' }))) return;
        try {
            await deletePhoto(photo.id);
            navigate(-1);
        } catch {
            showToast('error', 'Fehler beim Löschen');
        }
    };

    const openHistory = async () => {
        setIsHistoryOpen(true);
        setLoadingHistory(true);
        try {
            const versions = await getVersions(photo.id);
            setHistory(versions);
        } catch {
            showToast('error', 'Historie konnte nicht geladen werden.');
        }
        setLoadingHistory(false);
    };

    const handleRevert = async (versionId: number) => {
        if (!(await confirm({ title: 'Version wiederherstellen?', message: 'Möchtest du diese Metadaten-Version wirklich wiederherstellen? Dies überschreibt den aktuellen Stand.', confirmText: 'Wiederherstellen', confirmColor: 'warning' }))) return;
        try {
            await revertMetadata(photo.id, versionId);
            setIsHistoryOpen(false);
            mutate();
        } catch {
            showToast('error', 'Fehler beim Wiederherstellen der Version.');
        }
    };

    return (
        <PageLayout hideMobileHeader>
            <div className="container mx-auto p-4 md:p-8">
                {/* Header / Breadcrumbs */}
                <div className="flex items-center mb-6 gap-4">
                    <button onClick={() => navigate(-1)} className="btn btn-circle btn-ghost shrink-0">
                        <span className="iconify mdi--arrow-left text-2xl"></span>
                    </button>
                    <div className="text-sm breadcrumbs flex-1 overflow-hidden whitespace-nowrap">
                        <ul>
                            <li><a onClick={() => navigate('/')}>Dashboard</a></li>
                            {breadcrumbs.map((bc, idx) => (
                                <li key={idx}>
                                    {bc.type === 'gallery' && bc.full_path
                                        ? <a onClick={() => navigate('/' + bc.full_path)} className="font-semibold text-base-content opacity-80 hover:opacity-100 cursor-pointer">{bc.name}</a>
                                        : <span className="opacity-70">{bc.name}</span>
                                    }
                                </li>
                            ))}
                            <li className="truncate max-w-[200px] opacity-50">Bilddetails</li>
                        </ul>
                    </div>
                </div>

                {/* Content Area (Bild links, Formular rechts ab XL) */}
                <div className="flex flex-col xl:flex-row gap-8 items-start">

                    {/* Linke Spalte: Bild, Datei-Info & Löschen */}
                    <div className="flex-1 w-full flex flex-col gap-4">
                        <div className="bg-base-200 rounded-box flex items-center justify-center p-4 min-h-[40vh]">
                            <img src={photo.url} alt={photo.filename} className="max-w-full h-auto max-h-[70vh] object-contain rounded drop-shadow-xl"/>
                        </div>

                        <div className="flex flex-col sm:flex-row gap-4 justify-between items-center w-full bg-base-100 p-4 rounded-box border border-base-300 shadow-sm mt-2">
                            <div className="flex items-center gap-4">
                                <button onClick={() => window.open('/api/photos/' + photo.id + '/download', '_self')} className="btn btn-primary shadow-sm">
                                    <span className="iconify mdi--download text-lg"></span> Herunterladen
                                </button>
                                <span className="text-sm opacity-70 font-semibold">{data.downloads_count || 0} Downloads</span>
                            </div>
                            {user?.is_admin && (
                                <button onClick={handleDelete} className="btn btn-outline btn-error shrink-0 w-full sm:w-auto">
                                    <span className="iconify mdi--trash-can"></span> Bild löschen
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Rechte Spalte: Formular */}
                    <div className="w-full xl:w-[500px] shrink-0 flex flex-col gap-6">
                        <IptcMetadataEditor
                            data={iptcData}
                            onChange={setIptcData}
                            disabled={!canEdit}
                            showArtist={isPhotogOrAdmin}
                        >
                            {canEdit && (
                                <div className="flex gap-2 mt-6 pt-4 border-t border-base-300">
                                    <button onClick={handleSaveMeta} disabled={saving} className="btn btn-primary flex-1">
                                        {saving ? <span className="loading loading-spinner"></span> : 'Speichern'}
                                    </button>
                                    {isPhotogOrAdmin && (
                                        <button onClick={openHistory} className="btn btn-outline" title="Änderungshistorie anzeigen">
                                            <span className="iconify mdi--history"></span> Historie
                                        </button>
                                    )}
                                </div>
                            )}
                        </IptcMetadataEditor>

                        
                    </div>
                </div>
            </div>

            {/* History Modal */}
            {isHistoryOpen && (
                <div className="modal modal-open">
                    <div className="modal-box max-w-4xl relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setIsHistoryOpen(false)}>✕</button>
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
                    <div className="modal-backdrop" onClick={() => setIsHistoryOpen(false)}></div>
                </div>
            )}
        </PageLayout>
    );
}