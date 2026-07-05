import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import GalleryAccessModal from './components/GalleryAccessModal';
import {useRef, useState} from 'react';
import ErrorMessage from '../components/ErrorMessage';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import {useNavigate, useParams, useSearchParams} from 'react-router-dom';
import {useGallery} from '../../logic/useGallery';
import {flattenGroups} from '../../logic/utils';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import {useBrand} from '../../logic/useBrand';
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
import GalleryCouponsTab from './components/GalleryCouponsTab';
import EmptyState from '../components/EmptyState';

export default function ManagementGalleryView() {
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    const navigate = useNavigate();
    const { gallery, photos, downloadsCount, notified_count, isLoading, isError, size, setSize, isReachingEnd, mutate, breadcrumbs } = useGallery(slug);
    const {tree, updateGallery, deleteGallery} = useProtectedGalleries();
    const {user} = useAuth();
    const {isAdmin, isPhotographer} = usePermissions();
    const {isSrp} = useBrand();
    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab: 'bilder' | 'coupons' =
        isSrp && searchParams.get('tab') === 'coupons' ? 'coupons' : 'bilder';
    const setActiveTab = (tab: 'bilder' | 'coupons') => {
        setSearchParams(prev => {
            const updated = new URLSearchParams(prev);
            if (tab === 'bilder') {
                updated.delete('tab');
            } else {
                updated.set('tab', tab);
            }
            return updated;
        });
    };

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
    if (isError || !gallery) return <PageLayout><div className="p-8"><ErrorMessage message={t`Galerie nicht gefunden.`} /></div></PageLayout>;

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

                {isSrp && (
                    <div role="tablist" className="tabs tabs-boxed w-full md:w-auto bg-base-200 border border-base-300 p-1 flex-wrap shadow-sm mb-6">
                        <a
                            role="tab"
                            className={`tab ${activeTab === 'bilder' ? 'tab-active font-bold' : ''}`}
                            onClick={() => setActiveTab('bilder')}
                        >
                            <span className="iconify mdi--image-multiple-outline mr-1"></span>
                            <Trans>Bilder</Trans>
                        </a>
                        <a
                            role="tab"
                            className={`tab ${activeTab === 'coupons' ? 'tab-active font-bold' : ''}`}
                            onClick={() => setActiveTab('coupons')}
                        >
                            <span className="iconify mdi--ticket-percent-outline mr-1"></span>
                            <Trans>Coupons</Trans>
                        </a>
                    </div>
                )}

                {activeTab === 'bilder' && (
                    <>
                        {isPhotographer && (
                            <UploadDropzone galleryId={gallery.id} onUploadComplete={() => mutate()} />
                        )}

                        {!isLoading && photos.length === 0 && (
                            <EmptyState icon="mdi--image-off-outline" title="Noch keine Bilder vorhanden">
                                {gallery.is_live ? (
                                    <p className="mt-2 text-warning flex items-center gap-2">
                                        <span className="iconify mdi--autorenew animate-spin"></span>
                                        <Trans>Dies ist eine LIVE Galerie. Diese Ansicht aktualisiert sich automatisch alle 10 Sekunden.</Trans>
                                    </p>
                                ) : (
                                    <p className="mt-2"><Trans>Lade Bilder per Drag & Drop hoch oder importiere sie über die FTP-Inbox.</Trans></p>
                                )}
                            </EmptyState>
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
                                <button className="btn btn-outline" onClick={() => setSize(size + 1)}><Trans>Mehr laden</Trans></button>
                            </div>
                        )}
                    </>
                )}

                {activeTab === 'coupons' && isSrp && (
                    <GalleryCouponsTab galleryId={gallery.id} />
                )}

                <EmailComposerModal isOpen={isMailModalOpen} onClose={() => setIsMailModalOpen(false)} galleryId={gallery.id} />
                {isInviteModalOpen && <InviteModal galleryId={gallery.id} galleryType={gallery.type} onClose={() => setIsInviteModalOpen(false)}/>}
                <RatingStatusModal galleryId={gallery.id} isOpen={showRatingsModal} onClose={() => setShowRatingsModal(false)} />
                <GalleryAccessModal isOpen={isAccessModalOpen} onClose={() => setIsAccessModalOpen(false)} galleryId={gallery.id} galleryName={gallery.name} />
                <PhotographerTeamModal isOpen={isPhotographerTeamModalOpen} onClose={() => setIsPhotographerTeamModalOpen(false)} item={gallery} isGroup={false} onUpdateState={() => mutate()} />
                <GalleryMetadataDefaultsModal isOpen={isMetadataModalOpen} onClose={() => setIsMetadataModalOpen(false)} gallery={gallery} onUpdate={async (...args) => { await updateGallery(...args); mutate(); }} />
                {isAIBatchModalOpen && <AIBatchEditModal isOpen={isAIBatchModalOpen} onClose={() => setIsAIBatchModalOpen(false)} photos={photos} galleryId={gallery.id} />}

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
