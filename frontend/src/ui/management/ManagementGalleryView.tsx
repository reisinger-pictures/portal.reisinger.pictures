import {useRef, useState} from 'react';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import {useNavigate, useParams} from 'react-router-dom';
import {useGallery} from '../../logic/useGallery';
import {flattenGroups, Gallery, useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import EmailComposerModal from './components/EmailComposerModal';
import InviteModal from './components/InviteModal';
import PageLayout from '../components/PageLayout';
import GalleryHeader from '../components/GalleryHeader';
import GalleryModals from '../components/GalleryModals';
import UploadDropzone from './components/UploadDropzone';
import RatingStatusModal from './components/RatingStatusModal';
import GalleryMetadataDefaultsModal from './components/GalleryMetadataDefaultsModal';

export default function ManagementGalleryView() {
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    const navigate = useNavigate();
    const {
        gallery,
        photos,
        downloadsCount,
        isLoading,
        isError,
        size,
        setSize,
        isReachingEnd,
        mutate,
        breadcrumbs
    } = useGallery(slug);
    const {tree, updateGallery, deleteGallery} = useProtectedGalleries();
    const {user} = useAuth();

    const [isGalleryEditModalOpen, setGalleryEditModalOpen] = useState(false);
    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];

    const galleryRef = useRef<HTMLDivElement>(null);
    const [isMailModalOpen, setIsMailModalOpen] = useState(false);
    const [isInviteModalOpen, setIsInviteModalOpen] = useState(false);
    const [showRatingsModal, setShowRatingsModal] = useState(false);
    const [isMetadataModalOpen, setIsMetadataModalOpen] = useState(false);

    usePhotoSwipe({ galleryRef, dependencies: [photos.length] });

    if (isLoading && photos.length === 0) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span className="loading loading-spinner"></span></div>
    </PageLayout>;
    if (isError || !gallery) return <PageLayout>
        <div className="p-8 text-center text-error">Galerie nicht gefunden.</div>
    </PageLayout>;

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8">
                <GalleryHeader gallery={gallery} breadcrumbs={breadcrumbs} />
                
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <h1 className="text-3xl font-bold flex items-center gap-2">
                        {gallery.name}
                        {user?.is_photographer && (
                            <button onClick={() => setGalleryEditModalOpen(true)}
                                    className="btn btn-ghost btn-sm btn-circle tooltip tooltip-bottom"
                                    data-tip="Galerie bearbeiten">
                                <span className="iconify mdi--pencil text-xl"></span>
                            </button>
                        )}
                    </h1>
                    <div className="flex flex-wrap gap-4 items-center">
                        {gallery.type === 'delivery' && <span className="badge badge-ghost font-normal">{downloadsCount || 0} Downloads</span>}
                        {user?.is_photographer && (
                            <div className="flex gap-2">
                                {gallery.type === 'selection' && (
                                    <button onClick={() => setShowRatingsModal(true)} className="btn btn-secondary btn-sm">
                                        <span className="iconify mdi--star-outline"></span> Bewertungen...
                                    </button>
                                )}
                                {gallery.type === 'delivery' && (
                                    <button onClick={() => setIsMetadataModalOpen(true)} className="btn btn-secondary btn-sm">
                                        <span className="iconify mdi--tag-multiple"></span> Vorgaben...
                                    </button>
                                )}
                                <button onClick={() => setIsInviteModalOpen(true)} className="btn btn-outline btn-sm">
                                    <span className="iconify mdi--link"></span> Einladungslink...
                                </button>
                                <button onClick={() => setIsMailModalOpen(true)} className="btn btn-primary btn-sm">
                                    <span className="iconify mdi--email-fast"></span> E-Mail senden...
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {user?.is_photographer && (
                    <UploadDropzone galleryId={gallery.id} onUploadComplete={() => mutate()} />
                )}

                {!isLoading && photos.length === 0 && (
                    <div className="py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300">
                        <span className="iconify mdi--image-off-outline text-6xl mb-4"></span>
                        <h3 className="text-2xl font-bold">Noch keine Bilder vorhanden</h3>
                        {gallery.is_live ? (
                            <p className="mt-2 text-warning flex items-center gap-2">
                                <span className="iconify mdi--autorenew animate-spin"></span>
                                Dies ist eine LIVE Galerie. Diese Ansicht aktualisiert sich automatisch alle 10 Sekunden.
                            </p>
                        ) : (
                            <p className="mt-2">Lade Bilder per Drag & Drop hoch oder importiere sie über die FTP-Inbox.</p>
                        )}
                    </div>
                )}

                <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4" ref={galleryRef}>
                    {photos.map(photo => (
                        <div key={photo.id} className="relative group">
                            <a href={photo.url} data-pswp-width={photo.width || 2000}
                               data-pswp-height={photo.height || 1333}
                               data-title={photo.title}
                               data-desc={photo.description}
                               data-artist={photo.artist}
                               data-photo-id={photo.id}
                               className="pswp-item block relative aspect-square">
                                <img src={photo.thumb_url} className="object-cover w-full h-full rounded" loading="lazy" alt={photo.filename}/>
                            </a>
                            
                            {gallery.type === 'delivery' && (
                                <div className="absolute top-2 right-2 opacity-100 z-10">
                                    <button onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        navigate('/photos/' + photo.id);
                                    }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
                                        <span className="iconify mdi--open-in-new text-lg"></span>
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {!isReachingEnd && photos.length > 0 && (
                    <div className="text-center mt-8">
                        <button className="btn btn-outline" onClick={() => setSize(size + 1)}>Mehr laden</button>
                    </div>
                )}

                {/* --- Modals --- */}
                <EmailComposerModal isOpen={isMailModalOpen} onClose={() => setIsMailModalOpen(false)} galleryId={gallery.id} />
                
                {isInviteModalOpen && <InviteModal galleryId={gallery.id} onClose={() => setIsInviteModalOpen(false)}/>}
                
                <RatingStatusModal galleryId={gallery.id} isOpen={showRatingsModal} onClose={() => setShowRatingsModal(false)} />
                <GalleryMetadataDefaultsModal isOpen={isMetadataModalOpen} onClose={() => setIsMetadataModalOpen(false)} gallery={gallery as unknown as Gallery} onUpdate={async (...args) => { await updateGallery(...args); mutate(); }} />

                <GalleryModals
                    availableGroups={flattenGroups(safeGroups)}
                    isGroupModalOpen={false} setGroupModalOpen={() => {}}
                    isGalleryModalOpen={isGalleryEditModalOpen} setGalleryModalOpen={setGalleryEditModalOpen}
                    editingGallery={gallery as unknown as Gallery}
                    onCreateGroup={async () => {}} onCreateGallery={async () => {}}
                    onUpdateGroup={async () => {}}
                    onUpdateGallery={async (...args) => {
                        await updateGallery(...args);
                        mutate();
                    }}
                    onDeleteGroup={async () => {}}
                    onDeleteGallery={async (id) => {
                        await deleteGallery(id);
                        navigate('/');
                    }}
                />
            </div>
        </PageLayout>
    );
}
