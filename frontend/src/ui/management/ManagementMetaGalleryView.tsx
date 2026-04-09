import ResponsiveImage from '../components/ResponsiveImage';
import ErrorMessage from '../components/ErrorMessage';
import {useRef, useState} from 'react';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import {useNavigate, useParams} from 'react-router-dom';
import {flattenGroups, GalleryGroup, useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {useMetaGallery} from '../../logic/useMetaGallery';
import PageLayout from '../components/PageLayout';
import GalleryModals from '../components/GalleryModals';

export default function ManagementMetaGalleryView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {user} = useAuth();
    const {tree, updateGroup, deleteGroup} = useProtectedGalleries();
    const {
        group,
        photos,
        downloadsCount,
        isLoading,
        isError,
        size,
        setSize,
        isReachingEnd,
        mutate
    } = useMetaGallery(id);
    const [isGroupEditModalOpen, setGroupEditModalOpen] = useState(false);
    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];
    const galleryRef = useRef<HTMLDivElement>(null);

    usePhotoSwipe({ galleryRef, trigger: photos.length });

    if (isLoading && photos.length === 0) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span className="loading loading-spinner"></span></div>
    </PageLayout>;
    if (isError || !group) return <PageLayout>
        <div className="p-8"><ErrorMessage message="Meta-Galerie nicht gefunden." /></div>
    </PageLayout>;

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8">
                <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 className="text-3xl font-bold flex flex-wrap items-center gap-2">
                            Meta-Galerie: {group.name}
                            {user?.is_admin && (
                                <button onClick={() => setGroupEditModalOpen(true)}
                                        className="btn btn-ghost btn-sm btn-circle tooltip tooltip-bottom"
                                        data-tip="Meta-Galerie bearbeiten">
                                    <span className="iconify mdi--pencil text-xl"></span>
                                </button>
                            )}
                        </h1>
                        <p className="opacity-70 mt-2">Sammelansicht aller Fotos. Uploads sind nur in den Untergalerien
                            möglich.</p>
                    </div>
                    <div className="flex items-center">
                        <span className="badge badge-ghost font-normal">{downloadsCount || 0} Downloads gesamt</span>
                    </div>
                </div>

                {!isLoading && photos.length === 0 && (
                    <div
                        className="py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300">
                        <span className="iconify mdi--image-off-outline text-6xl mb-4"></span>
                        <h3 className="text-2xl font-bold">Noch keine Bilder vorhanden</h3>
                        <p className="mt-2">Es befinden sich noch keine Bilder in den untergeordneten Galerien.</p>
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
                                <ResponsiveImage src={photo.thumb_url} srcSet={photo.srcset} containerClassName="absolute inset-0 w-full h-full" className="object-cover w-full h-full rounded shadow-sm hover:shadow-md transition-shadow" alt={photo.title || 'Bild'} />
                            </a>
                            <div className="absolute top-2 right-2 opacity-100 z-10">
                                <button onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    navigate('/photos/' + photo.id);
                                }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
                                    <span className="iconify mdi--open-in-new text-lg"></span>
                                </button>
                            </div>
                            <div
                                className="absolute top-0 left-0 bg-black/60 text-white text-[10px] px-1.5 py-0.5 rounded-br truncate max-w-full">
                                {photo.gallery?.name}
                            </div>
                        </div>
                    ))}
                </div>

                {!isReachingEnd && photos.length > 0 && (
                    <div className="text-center mt-8">
                        <button className="btn btn-outline" onClick={() => setSize(size + 1)}>Mehr laden</button>
                    </div>
                )}

                <GalleryModals
                    availableGroups={flattenGroups(safeGroups)}
                    isGroupModalOpen={isGroupEditModalOpen} setGroupModalOpen={setGroupEditModalOpen}
                    isGalleryModalOpen={false} setGalleryModalOpen={() => {
                }}
                    editingGroup={group as unknown as GalleryGroup}
                    onCreateGroup={async () => {
                    }} onCreateGallery={async () => {
                }}
                    onUpdateGroup={async (id, name, slug, isPub, parentId) => {
                        await updateGroup(id, name, slug, isPub, parentId);
                        mutate();
                    }}
                    onUpdateGallery={async () => {
                    }}
                    onDeleteGroup={async (id) => {
                        await deleteGroup(id);
                        navigate('/');
                    }}
                    onDeleteGallery={async () => {
                    }}
                />
            </div>
        </PageLayout>
    );
}