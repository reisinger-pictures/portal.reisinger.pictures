import ResponsiveImage from '../components/ResponsiveImage';
import { useRef } from 'react';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import { useNavigate } from 'react-router-dom';
import PageLayout from '../components/PageLayout';
import GalleryHeader from '../components/GalleryHeader';
import { useAuth } from '../../logic/useAuth';


import { useGallery } from '../../logic/useGallery';
import { usePricing, ResolutionTier } from '../../logic/usePricing';
export interface DeliveryViewProps {
    galleryData: ReturnType<typeof useGallery>;
}

export default function DeliveryView({ galleryData }: DeliveryViewProps) {
    const navigate = useNavigate();
    const { user } = useAuth();
    const { gallery, photos, isLoading, totalPhotos, size, setSize, isReachingEnd } = galleryData;
    const { isCovered } = usePricing();


    /* moved early return */
    const galleryRef = useRef<HTMLDivElement>(null);

    usePhotoSwipe({ galleryRef, trigger: photos.length });

    if (!gallery) return null;

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8 relative">
                <GalleryHeader gallery={gallery} breadcrumbs={galleryData.breadcrumbs} canManage={galleryData.canManage} />
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h1 className="text-3xl font-bold mb-2">{gallery.name}</h1>
                        <p className="opacity-70">Lade deine Bilder herunter.</p> 
                
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-3 md:gap-4">
                        {user && (
                            <label className="cursor-pointer label gap-2 md:gap-3 bg-base-100 p-2 md:p-3 rounded-box border border-base-300 shadow-sm m-0">
                                <span className="iconify mdi--bell-ring-outline text-xl text-primary hidden md:inline-block"></span>
                                <div className="text-right">
                                    <span className="font-bold text-sm block leading-none mb-1">E-Mail Updates</span>
                                </div>
                                <input type="checkbox" className="toggle toggle-primary toggle-sm md:toggle-md" checked={galleryData.wantsNotifications} onChange={(e) => galleryData.toggleOptIn(gallery.id, e.target.checked)} />
                            </label>
                        )}
                        {totalPhotos > 0 && (() => {
                            const allowedTiers = [
                                { id: 'web', label: 'Web & Social' },
                                { id: 'print', label: 'Print (A4)' },
                                { id: 'original', label: 'Original' }
                            ].filter(t => isCovered(user?.flatrate_level, t.id as ResolutionTier, 'editorial', '1_year') || gallery?.effective_is_free_download || user?.is_admin || user?.is_photographer);

                            if (allowedTiers.length === 0) return null;

                            if (allowedTiers.length === 1) {
                                return (
                                    <a href={'/api/galleries/' + gallery.id + '/download-zip?tier=' + allowedTiers[0].id} target="_self" className="btn btn-primary">
                                        <span className="iconify mdi--zip-box text-xl hidden sm:inline-block mr-1"></span> Alle herunterladen (.zip)
                                    </a>
                                );
                            }

                            return (
                                <div className="dropdown dropdown-end">
                                    <div tabIndex={0} role="button" className="btn btn-primary">
                                        <span className="iconify mdi--zip-box text-xl hidden sm:inline-block mr-1"></span> Alle herunterladen (.zip) <span className="iconify mdi--chevron-down"></span>
                                    </div>
                                    <ul tabIndex={0} className="dropdown-content z-[1] menu p-2 shadow-xl bg-base-100 border border-base-300 rounded-box w-52">
                                        <li className="menu-title px-4 py-2 text-xs opacity-70 uppercase tracking-wider">Qualität wählen</li>
                                        {allowedTiers.map(t => (
                                            <li key={t.id}>
                                                <a href={'/api/galleries/' + gallery.id + '/download-zip?tier=' + t.id} target="_self" className="font-bold">
                                                    <span className="uppercase">{t.id}</span> Format
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })()}
                    </div>
                </div>

                {!isLoading && photos.length === 0 && (
                    <div className="py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300 mb-8">
                        <span className="iconify mdi--image-off-outline text-6xl mb-4 text-primary"></span>
                        <h3 className="text-2xl font-bold">Noch keine Bilder vorhanden</h3>
                        {gallery.is_live ? (
                            <p className="mt-2 text-warning flex items-center gap-2">
                                <span className="iconify mdi--autorenew animate-spin"></span>
                                Dies ist eine LIVE Galerie. Sobald der Fotograf Bilder hochlädt, erscheinen sie hier (automatischer Refresh alle 10s).
                            </p>
                        ) : (
                            <p className="mt-2">Der Fotograf hat noch keine Bilder für diese Galerie freigegeben.</p>
                        )}
                    </div>
                )}
                
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-6" ref={galleryRef}>
                    {photos.map((photo) => (
                        <div key={photo.id} className="card bg-base-200 shadow-xl overflow-hidden relative group">
                            <a href={photo.url}
                               data-pswp-width={photo.width || 2000}
                               data-pswp-height={photo.height || 1333}
                               data-title={photo.title}
                               data-desc={photo.description}
                               data-artist={photo.artist}
                               data-photo-id={photo.id}
                               className="pswp-item block relative aspect-square">
                                <ResponsiveImage src={photo.thumb_url} srcSet={photo.srcset} containerClassName="absolute inset-0 w-full h-full rounded" className="object-cover w-full h-full select-none" draggable={false} alt={photo.title || 'Bild'} />
                            </a>

                            <div className="absolute top-2 right-2 opacity-100">
                                <button onClick={(e) => {
                                    e.preventDefault();
                                    navigate('/photos/' + photo.id);
                                }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
                                    <span className="iconify mdi--open-in-new text-lg"></span>
                                </button>
                            </div>

                            <div className="card-body p-4 bg-base-100 flex flex-col gap-3">
                                <button onClick={(e) => { e.preventDefault(); navigate('/photos/' + photo.id); }} className="btn btn-secondary btn-sm w-full">
                                    <span className="iconify mdi--open-in-new mr-1"></span> Bild öffnen
                                </button>
                            </div>
                        </div>
                    ))}
                </div>

                {!isReachingEnd && photos.length > 0 && (
                    <div className="text-center mt-8">
                        <button className="btn btn-outline" onClick={() => setSize(size + 1)}>Mehr laden</button>
                    </div>
                )}
            </div>

        </PageLayout>
    );
}