import {useState, useEffect} from 'react';
import {useNavigate, useParams} from 'react-router-dom';
import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from '../logic/useGallery';
import {useAuth} from '../logic/useAuth';
import {usePhoto, PhotoVersion} from '../logic/usePhoto';
import PageLayout from './components/PageLayout';
import IptcMetadataEditor, { IptcData } from './components/IptcMetadataEditor';

interface Breadcrumb {
    name: string;
    type: 'group' | 'gallery';
    full_path?: string;
}

interface PhotoContextData {
    photo: Photo;
    breadcrumbs: Breadcrumb[];
}

export default function PhotoDetailView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {user} = useAuth();
    const {updateMetadata, deletePhoto, getVersions, revertMetadata} = usePhoto();

    const {data, error, isLoading, mutate} = useSWR<PhotoContextData>(
        id ? '/api/photos/' + id + '/context' : null, fetcher
    );

    const [iptcData, setIptcData] = useState<IptcData>({});
    const [saving, setSaving] = useState(false);

    // History Modal State
    const [isHistoryOpen, setIsHistoryOpen] = useState(false);
    const [history, setHistory] = useState<PhotoVersion[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);

    const [prevPhoto, setPrevPhoto] = useState<Photo | null>(null);

    useEffect(() => {
        if (data?.photo && data.photo !== prevPhoto) {
            setPrevPhoto(data.photo);
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
    }, [data, prevPhoto]);

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
        } catch {
            alert('Fehler beim Speichern');
        }
        setSaving(false);
    };

    const handleDelete = async () => {
        if (!window.confirm('Bild wirklich endgültig löschen?')) return;
        try {
            await deletePhoto(photo.id);
            navigate(-1);
        } catch {
            alert('Fehler beim Löschen');
        }
    };

    const openHistory = async () => {
        setIsHistoryOpen(true);
        setLoadingHistory(true);
        try {
            const versions = await getVersions(photo.id);
            setHistory(versions);
        } catch {
            alert('Historie konnte nicht geladen werden.');
        }
        setLoadingHistory(false);
    };

    const handleRevert = async (versionId: number) => {
        if (!window.confirm('Möchtest du diese Metadaten-Version wirklich wiederherstellen? Dies überschreibt den aktuellen Stand.')) return;
        try {
            await revertMetadata(photo.id, versionId);
            setIsHistoryOpen(false);
            mutate(); // Lade das aktuelle Foto nach dem Revert neu
        } catch {
            alert('Fehler beim Wiederherstellen der Version.');
        }
    };

    return (
        <PageLayout hideMobileHeader>
            <div className="container mx-auto p-4 md:p-8 flex flex-col md:flex-row h-full gap-6">
                <div className="flex-1 flex flex-col h-full min-w-0">
                    <div className="flex items-center mb-6 gap-4 shrink-0">
                        <button onClick={() => navigate(-1)} className="btn btn-circle btn-ghost">
                            <span className="iconify mdi--arrow-left text-2xl"></span>
                        </button>
                        <div className="text-sm breadcrumbs flex-1 overflow-hidden whitespace-nowrap">
                            <ul>
                                <li><a onClick={() => navigate('/')}>Dashboard</a></li>
                                {breadcrumbs.map((bc, idx) => (
                                    <li key={idx}>
                                        {bc.type === 'gallery' && bc.full_path
                                            ? <a onClick={() => navigate('/' + bc.full_path)} className="font-semibold text-primary">{bc.name}</a>
                                            : <span className="opacity-70">{bc.name}</span>
                                        }
                                    </li>
                                ))}
                                <li className="truncate max-w-[200px]">{photo.filename}</li>
                            </ul>
                        </div>
                    </div>

                    <div className="flex-1 bg-base-200 rounded-box flex flex-col items-center justify-center p-4 relative overflow-hidden shadow-inner min-h-[300px]">
                        <img src={photo.url} alt={photo.filename} className="max-w-full max-h-full object-contain rounded drop-shadow-2xl"/>
                    </div>
                </div>

                <div className="w-full md:w-[450px] flex flex-col gap-6 pt-4 md:pt-16 shrink-0 overflow-y-auto pb-8">
                    
                    <IptcMetadataEditor 
                        data={iptcData} 
                        onChange={setIptcData} 
                        disabled={!canEdit} 
                        showArtist={isPhotogOrAdmin} 
                    />
                    
                    {canEdit && (
                        <div className="flex gap-2">
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

                    <div className="text-sm opacity-50 px-2 bg-base-200 p-4 rounded-box">
                        <p className="font-bold mb-2">Datei-Info</p>
                        <p>Originalname: {photo.filename}</p>
                        <p>Format: {photo.width} x {photo.height}px</p>
                        <p className="truncate">UUID: {photo.lr_uuid}</p>
                    </div>

                    {user?.is_admin && (
                        <button onClick={handleDelete} className="btn btn-outline btn-error w-full mt-auto">
                            <span className="iconify mdi--trash-can"></span> Bild löschen
                        </button>
                    )}
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
