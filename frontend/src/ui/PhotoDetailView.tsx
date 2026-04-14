import {useState} from 'react';
import ErrorMessage from './components/ErrorMessage';
import {useNavigate, useParams} from 'react-router-dom';
import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from '../logic/useGallery';
import {useAuth} from '../logic/useAuth';
import {usePhoto} from '../logic/usePhoto';
import PageLayout from './components/PageLayout';
import IptcMetadataEditor, { IptcData } from './components/IptcMetadataEditor';
import ResponsiveImage from './components/ResponsiveImage';
import PhotoHistoryModal from './components/PhotoHistoryModal';
import { useUI } from './components/UIContext';
import LicenseSelectorCard from './client/components/LicenseSelectorCard';

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
    const {updateMetadata, deletePhoto} = usePhoto();
    const { showToast, confirm } = useUI();
    const {data, error, isLoading, mutate} = useSWR<PhotoContextData>(
        id ? '/api/photos/' + id + '/context' : null, fetcher
    );

    const [iptcData, setIptcData] = useState<IptcData>({});
    const [saving, setSaving] = useState(false);

    const [isHistoryOpen, setIsHistoryOpen] = useState(false);
    const [prevPhotoId, setPrevPhotoId] = useState<string | undefined>(undefined);


    if (data?.photo && data.photo.id !== prevPhotoId) {
        setPrevPhotoId(data.photo.id);
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

    if (isLoading) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div>

        </PageLayout>;
    if (error || !data) return <PageLayout>
        <div className="p-8"><ErrorMessage message="Foto konnte nicht geladen werden oder keine Berechtigung." /></div>
    </PageLayout>;

    const {photo, breadcrumbs} = data;
    const isPhotographer = user?.is_super_admin || user?.is_admin || (user?.is_photographer && data?.photo && (user?.my_galleries?.some(g => g.id === data.photo.gallery_id) || user?.photographer_galleries?.some(g => g.id === data.photo.gallery_id)));
    const canEdit = isPhotographer || ((user?.can_edit_metadata || user?.transient_meta_galleries?.includes(data?.photo?.gallery_id)) && data?.photo?.gallery?.allow_client_metadata_edit);

    const handleSaveMeta = async () => {
        setSaving(true);
        try {
            await updateMetadata(photo.id, iptcData);
            await mutate();
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

                {/* Content Area */}
                <div className="flex flex-col xl:flex-row gap-8 items-start">

                    {/* Linke Spalte */}
                    <div className="flex-1 w-full flex flex-col gap-4">
                        <div className="bg-base-200 rounded-box flex items-center justify-center p-4 min-h-[40vh] overflow-hidden">
                            <ResponsiveImage src={photo.url} alt={photo.title || 'Bild'} containerClassName="flex items-center justify-center w-full h-full min-h-[40vh] bg-transparent" className="max-w-full h-auto max-h-[70vh] object-contain rounded drop-shadow-xl" />
                        </div>

                        {isPhotographer && (<div className="flex justify-end w-full bg-base-100 p-4 rounded-box border border-base-300 shadow-sm mt-2"><button onClick={handleDelete} className="btn btn-outline btn-error shrink-0 w-full sm:w-auto whitespace-nowrap"><span className="iconify mdi--trash-can"></span> Bild löschen</button></div>)}
                    </div>

                    {/* Rechte Spalte: Formular */}
                    <div className="w-full lg:w-[450px] xl:w-[600px] 2xl:w-[700px] shrink-0 flex flex-col gap-6">
                        {data.photo.gallery?.type === 'delivery' && (
                            <LicenseSelectorCard photo={data.photo} />
                        )}
                        <IptcMetadataEditor
                            data={iptcData}
                            onChange={setIptcData}
                            disabled={!canEdit}
                            showArtist={isPhotographer}
                        >
                            {canEdit && (
                                <div className="flex flex-col sm:flex-row gap-2 mt-6 pt-4 border-t border-base-300">
                                    <button onClick={handleSaveMeta} disabled={saving} className="btn btn-primary flex-1 w-full">
                                        {saving ? <span className="loading loading-spinner"></span> : 'Speichern'}
                                    </button>
                                    {isPhotographer && (
                                        <button onClick={() => setIsHistoryOpen(true)} className="btn btn-outline w-full sm:w-auto" title="Änderungshistorie anzeigen">
                                            <span className="iconify mdi--history"></span> Historie
                                        </button>
                                    )}
                                </div>
                            )}
                        </IptcMetadataEditor>
                    </div>
                </div>
            </div>

            <PhotoHistoryModal photoId={photo.id} isOpen={isHistoryOpen} onClose={() => setIsHistoryOpen(false)} onReverted={async () => {
            const newData = await mutate();
            if (newData?.photo) {
                setIptcData({
                    title: newData.photo.title || '',
                    description: newData.photo.description || '',
                    artist: newData.photo.artist || '',
                    headline: newData.photo.headline || '',
                    keywords: newData.photo.keywords || '',
                    location: newData.photo.location || '',
                    city: newData.photo.city || '',
                    state: newData.photo.state || '',
                    country: newData.photo.country || '',
                    iso_country: newData.photo.iso_country || ''
                });
            }
        }} />
        </PageLayout>
    );
}