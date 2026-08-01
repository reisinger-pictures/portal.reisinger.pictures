import {useState} from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import ErrorMessage from './components/ErrorMessage';
import {useNavigate, useParams} from 'react-router-dom';
import useSWR from 'swr';
import {fetcher} from '../api';
import {Photo} from '../logic/useGallery';
import {useAuth} from '../logic/useAuth';
import {usePermissions} from '../logic/usePermissions';
import {usePhoto} from '../logic/usePhoto';
import PageLayout from './components/PageLayout';
import IptcMetadataEditor from './components/IptcMetadataEditor';
import { IptcData } from '../logic/usePhoto';
import ResponsiveImage from './components/ResponsiveImage';
import PhotoHistoryModal from './components/PhotoHistoryModal';
import { useUI } from './components/UIContext';
import { useAI } from '../logic/useAI';
import LicenseSelectorCard from './client/components/LicenseSelectorCard';
import VolumeLicensingCard from './client/components/VolumeLicensingCard';
import {useLicensingMode} from '../logic/useLicensingMode';
import { BreadcrumbItem } from '../api';
import { useEffect } from 'react';
import { trackEvent, TRACKING_EVENTS } from '../logic/tracking';

interface PhotoContextData {
    photo: Photo;
    breadcrumbs: BreadcrumbItem[];
    downloads_count: number;
}

export default function PhotoDetailView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {user} = useAuth();
    const {isSuperAdmin, isAdmin, isPhotographer, canEditMetadata} = usePermissions();
    const {updateMetadata, deletePhoto} = usePhoto();
    const { isAvailable, generateMetadata } = useAI();
    const { showToast, confirm } = useUI();
    const {data, error, isLoading, mutate} = useSWR<PhotoContextData>(id ? '/api/photos/' + id + '/context' : null, fetcher);

    const [iptcData, setIptcData] = useState<IptcData>({});
    const [saving, setSaving] = useState(false);
    const [isHistoryOpen, setIsHistoryOpen] = useState(false);
    const [prevPhotoId, setPrevPhotoId] = useState<string | undefined>(undefined);
    const [aiContext, setAiContext] = useState('');
    const [isAiGenerating, setIsAiGenerating] = useState(false);
    const licensingMode = useLicensingMode();

    const photoId = data?.photo?.id;
    if (photoId && photoId !== prevPhotoId) {
        setPrevPhotoId(photoId);
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
            iso_country: data.photo.iso_country || '',
            is_editorial_only: data.photo.is_editorial_only || false,
            effective_is_editorial_only: data.photo.effective_is_editorial_only || false
        });
    }

    useEffect(() => {
        if (photoId) {
            trackEvent(TRACKING_EVENTS.photo_view, {
                photo_id: photoId,
                gallery_id: data?.photo?.gallery_id,
            });
        }
    }, [photoId, data?.photo?.gallery_id]);

    if (isLoading) return <PageLayout><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (error || !data) return <PageLayout><div className="p-8"><ErrorMessage message={t`Foto konnte nicht geladen werden oder keine Berechtigung.`} /></div></PageLayout>;

    const {photo, breadcrumbs} = data;
    const isPhotographerUser = isSuperAdmin || isAdmin || (isPhotographer && data?.photo && (user?.my_galleries?.some(g => g.id === data.photo.gallery_id) || user?.photographer_galleries?.some(g => g.id === data.photo.gallery_id)));
    const canEdit = isPhotographerUser || ((canEditMetadata || user?.transient_meta_galleries?.includes(data?.photo?.gallery_id)) && data?.photo?.gallery?.allow_client_metadata_edit);

    const galleryDefaults = data?.photo?.gallery
        ? [data.photo.gallery.default_title, data.photo.gallery.default_description, data.photo.gallery.default_keywords]
            .filter(Boolean)
            .join(' | ')
        : '';

    const handleSaveMeta = async () => {
        setSaving(true);
        try {
            await updateMetadata(photo.id, iptcData);
            await mutate();
            showToast('success', t`Metadaten gespeichert`);
        } catch {
            showToast('error', t`Fehler beim Speichern.`);
        }
        setSaving(false);
    };

    const handleDelete = async () => {
        if (!(await confirm({ title: t`Bild löschen?`, message: t`Möchtest du dieses Bild wirklich endgültig löschen?`, confirmText: t`Löschen`, confirmColor: 'error' }))) return;
        try {
            await deletePhoto(photo.id);
            navigate(-1);
        } catch {
            showToast('error', t`Fehler beim Löschen`);
        }
    };

    const handleAiGenerate = async () => {
        setIsAiGenerating(true);
        try {
            const result = await generateMetadata(photo.id, galleryDefaults, aiContext);
            setIptcData(prev => ({
                ...prev,
                title: result.title || prev.title,
                description: result.description || prev.description,
                keywords: result.keywords || prev.keywords,
                location: result.location || prev.location
            }));
            showToast('success', t`KI-Vorschlag geladen — jetzt prüfen und speichern.`);
        } catch {
            showToast('error', t`KI-Generierung fehlgeschlagen.`);
        }
        setIsAiGenerating(false);
    };

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8">
                <div className="flex items-center mb-6 gap-4">
                    <button onClick={() => navigate(-1)} className="btn btn-circle btn-ghost shrink-0"><span className="iconify mdi--arrow-left text-2xl"></span></button>
                    <div className="text-sm breadcrumbs flex-1 overflow-hidden whitespace-nowrap">
                        <ul>
                            <li><a onClick={() => navigate('/')}><Trans>Dashboard</Trans></a></li>
                            {breadcrumbs.map((bc, idx) => (
                                <li key={idx}>
                                    {bc.type === 'gallery' && bc.full_path ? <a onClick={() => navigate('/' + bc.full_path)} className="font-semibold text-base-content opacity-80 hover:opacity-100 cursor-pointer">{bc.name}</a> : <span className="opacity-70">{bc.name}</span>}
                                </li>
                            ))}
                            <li className="truncate max-w-48 opacity-50"><Trans>Bilddetails</Trans></li>
                        </ul>
                    </div>
                </div>
                <h1 className="text-2xl font-bold mb-6">{photo.title || t`Bilddetails`}</h1>

                <div className="flex flex-col gap-8 items-start">
                    <div className="w-full flex flex-col gap-4">
                        <div className="bg-base-200 rounded-box flex items-center justify-center p-4 min-h-96 overflow-hidden">
                            <ResponsiveImage src={photo.url} alt={photo.title || t`Bild`} containerClassName="flex items-center justify-center w-full h-full min-h-96 bg-transparent" className="max-w-full h-auto max-h-screen object-contain rounded drop-shadow-xl" />
                        </div>
                        {isPhotographerUser && (<div className="flex justify-end w-full bg-base-100 p-4 rounded-box border border-base-300 shadow-sm mt-2"><button onClick={handleDelete} className="btn btn-outline btn-error shrink-0 w-full sm:w-auto whitespace-nowrap"><span className="iconify mdi--trash-can"></span> <Trans>Bild löschen</Trans></button></div>)}
                    </div>

                    <div className="w-full grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
                        {data.photo.gallery?.type === 'delivery' && (
                            licensingMode === 'volume_licensing' ? (
                                <VolumeLicensingCard photo={data.photo} onAddToCart={() => {}} />
                            ) : (
                                <LicenseSelectorCard photo={data.photo} />
                            )
                        )}
                        <IptcMetadataEditor data={iptcData} onChange={setIptcData} disabled={!canEdit} showArtist={isPhotographerUser} showEditorialFlag={true} capturedAt={data.photo.captured_at}>
                            {canEdit && (
                                <>
                                    <div className="mt-4 p-3 bg-base-200 rounded-box border border-base-300">
                                        <div className="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                                            <input
                                                type="text"
                                                value={aiContext}
                                                onChange={e => setAiContext(e.target.value)}
                                                placeholder={t`Zusätzlicher Kontext für KI`}
                                                className="input input-sm input-bordered flex-1 w-full"
                                            />
                                            <button
                                                onClick={handleAiGenerate}
                                                disabled={!isAvailable || isAiGenerating}
                                                className="btn btn-sm btn-primary w-full sm:w-auto"
                                            >
                                                {isAiGenerating ? (
                                                    <span className="loading loading-spinner loading-xs"></span>
                                                ) : (
                                                    <><span className="iconify mdi--auto-fix"></span> <Trans>KI generieren</Trans></>
                                                )}
                                            </button>
                                        </div>
                                        {galleryDefaults && (
                                            <p className="text-xs opacity-50 mt-1"><Trans>Gallery-Kontext: {galleryDefaults}</Trans></p>
                                        )}
                                    </div>
                                    <div className="flex flex-col sm:flex-row gap-2 mt-6 pt-4 border-t border-base-300">
                                        <button onClick={handleSaveMeta} disabled={saving} className="btn btn-primary flex-1 w-full">{saving ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}</button>
                                        {isPhotographerUser && (<button onClick={() => setIsHistoryOpen(true)} className="btn btn-outline w-full sm:w-auto" title={t`Änderungshistorie anzeigen`}><span className="iconify mdi--history"></span> <Trans>Historie</Trans></button>)}
                                    </div>
                                </>
                            )}
                        </IptcMetadataEditor>
                    </div>
                </div>
            </div>

            <PhotoHistoryModal photoId={photo.id} isOpen={isHistoryOpen} onClose={() => setIsHistoryOpen(false)} onReverted={async () => {
                const newData = await mutate();
                if (newData?.photo) {
                    setIptcData({
                        title: newData.photo.title || '', description: newData.photo.description || '', artist: newData.photo.artist || '',
                        headline: newData.photo.headline || '', keywords: newData.photo.keywords || '', location: newData.photo.location || '',
                        city: newData.photo.city || '', state: newData.photo.state || '', country: newData.photo.country || '',
                        iso_country: newData.photo.iso_country || '', is_editorial_only: newData.photo.is_editorial_only || false, effective_is_editorial_only: newData.photo.effective_is_editorial_only || false
                    });
                }
            }} />
        </PageLayout>
    );
}
