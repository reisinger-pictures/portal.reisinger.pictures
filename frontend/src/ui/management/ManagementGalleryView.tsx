import GalleryAccessModal from './components/GalleryAccessModal';
import {useRef, useState} from 'react';
import ErrorMessage from '../components/ErrorMessage';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import {useNavigate, useParams} from 'react-router-dom';
import {useGallery} from '../../logic/useGallery';
import {flattenGroups, useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import EmailComposerModal from './components/EmailComposerModal';
import InviteModal from './components/InviteModal';
import PageLayout from '../components/PageLayout';
import GalleryHeader from '../components/GalleryHeader';
import UploadDropzone from './components/UploadDropzone';
import RatingStatusModal from './components/RatingStatusModal';
import GalleryMetadataDefaultsModal from './components/GalleryMetadataDefaultsModal';
import GalleryModals from '../components/GalleryModals';
import ManagementGalleryActions from './components/ManagementGalleryActions';
import PhotographerTeamModal from './components/PhotographerTeamModal';
import AIBatchEditModal from './components/AIBatchEditModal';

export default function ManagementGalleryView() {
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    const navigate = useNavigate();
    const { gallery, photos, downloadsCount, notified_count, isLoading, isError, size, setSize, isReachingEnd, mutate, breadcrumbs } = useGallery(slug);
    const {tree, updateGallery, deleteGallery} = useProtectedGalleries();
    const {user} = useAuth();
    const {isAdmin, isPhotographer} = usePermissions();

    const [isGalleryEditModalOpen, setGalleryEditModalOpen] = useState(false);
    const availableGroups = tree ? flattenGroups(tree.groups) : [];

    const galleryRef = useRef<HTMLDivElement>(null);
    const [isMailModalOpen, setIsMailModalOpen] = useState(false);
    const [isInviteModalOpen, setIsInviteModalOpen] = useState(false);
    const [showRatingsModal, setShowRatingsModal] = useState(false);
    const [isMetadataModalOpen, setIsMetadataModalOpen] = useState(false);
    const [isAccessModalOpen, setIsAccessModalOpen] = useState(false);
    const [isPhotographerTeamModalOpen, setIsPhotographerTeamModalOpen] = useState(false);
    const [isAIBatchModalOpen, setIsAIBatchModalOpen] = useState(false);

    usePhotoSwipe({ galleryRef, trigger: photos.length });

    if (isLoading && photos.length === 0) return <PageLayout><div className="flex h-full items-center justify-center"><span className="loading loading-spinner"></span></div></PageLayout>;
    if (isError || !gallery) return <PageLayout><div className="p-8"><ErrorMessage message="Galerie nicht gefunden." /></div></PageLayout>;

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8">
                <GalleryHeader gallery={gallery} breadcrumbs={breadcrumbs} canManage={true} />

                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <h1 className="text-3xl font-bold flex items-center gap-2">
                        {gallery.name}
                        {isPhotographer && (
                            <button onClick={() => setGalleryEditModalOpen(true)} className="btn btn-ghost btn-sm btn-circle tooltip tooltip-bottom" data-tip="Galerie bearbeiten">
                                <span className="iconify mdi--pencil text-xl"></span>
                            </button>
                        )}
                    </h1>
                    
                    <ManagementGalleryActions
                        gallery={gallery}
                        canSendMail={(notified_count || 0) > 0}
                        downloadsCount={downloadsCount || 0}
                        isPhotographer={isPhotographer}
                        onOpenRatings={() => setShowRatingsModal(true)}
                        onOpenMetadata={() => setIsMetadataModalOpen(true)}
                        onOpenInvite={() => setIsInviteModalOpen(true)}
                        onOpenAccess={isAdmin ? () => setIsAccessModalOpen(true) : undefined}
                        onOpenPhotographerTeam={isAdmin || isPhotographer ? () => setIsPhotographerTeamModalOpen(true) : undefined}
                        onOpenAIBatchEdit={isPhotographer && !user?.ai_is_unconfigured ? () => setIsAIBatchModalOpen(true) : undefined}
                        onOpenMail={() => setIsMailModalOpen(true)}
                    />
                </div>

                {isPhotographer && (
                    <UploadDropzone galleryId={gallery.id} onUploadComplete={() => mutate()} />
                )}

                {!isLoading && photos.length === 0 && (
                    <div className="py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300">
                        <span className="iconify mdi--image-off-outline text-6xl mb-4 text-primary"></span>
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
                            <a href={photo.url} data-pswp-width={photo.width || 2000} data-pswp-height={photo.height || 1333} data-title={photo.title} data-desc={photo.description} data-artist={photo.artist} data-photo-id={photo.id} className="pswp-item block relative aspect-square">
                                <img src={photo.thumb_url} className="object-cover w-full h-full rounded hover:scale-105 transition-transform duration-500" loading="lazy" alt={photo.title || 'Bild'}/>
                            </a>
                            {gallery.type === 'delivery' && (
                                <div className="absolute top-2 right-2 opacity-100 z-10">
                                    <button onClick={(e) => { e.preventDefault(); e.stopPropagation(); navigate('/photos/' + photo.id); }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
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

                <EmailComposerModal isOpen={isMailModalOpen} onClose={() => setIsMailModalOpen(false)} galleryId={gallery.id} />
                {isInviteModalOpen && <InviteModal galleryId={gallery.id} galleryType={gallery.type} onClose={() => setIsInviteModalOpen(false)}/>}
                <RatingStatusModal galleryId={gallery.id} isOpen={showRatingsModal} onClose={() => setShowRatingsModal(false)} />
                <GalleryAccessModal isOpen={isAccessModalOpen} onClose={() => setIsAccessModalOpen(false)} galleryId={gallery.id} galleryName={gallery.name} />
                <PhotographerTeamModal isOpen={isPhotographerTeamModalOpen} onClose={() => setIsPhotographerTeamModalOpen(false)} item={gallery} isGroup={false} onUpdateState={() => mutate()} />
                <GalleryMetadataDefaultsModal isOpen={isMetadataModalOpen} onClose={() => setIsMetadataModalOpen(false)} gallery={gallery} onUpdate={async (...args) => { await updateGallery(...args); mutate(); }} />
                <AIBatchEditModal isOpen={isAIBatchModalOpen} onClose={() => setIsAIBatchModalOpen(false)} photos={photos} galleryId={gallery.id} />

                <GalleryModals
                    availableGroups={availableGroups}
                    isGroupModalOpen={false} setGroupModalOpen={() => {}}
                    isGalleryModalOpen={isGalleryEditModalOpen} setGalleryModalOpen={setGalleryEditModalOpen}
                    editingGallery={gallery}
                    onCreateGroup={async () => {}} onCreateGallery={async () => {}}
                    onUpdateGroup={async () => {}}
                    onUpdateGallery={async (...args) => { await updateGallery(...args); mutate(); }}
                    onDeleteGroup={async () => {}}
                    onDeleteGallery={async (id) => { await deleteGallery(id); navigate('/'); }}
                />
            </div>
        </PageLayout>
    );
}
