import ResponsiveImage from '../components/ResponsiveImage';
import { useEffect, useRef, useState } from 'react';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import { createPortal } from 'react-dom';
import DaisyUIRatingBridge from './components/DaisyUIRatingBridge';
import GridPhotoActions from './components/GridPhotoActions';
import { useAuth } from '../../logic/useAuth';
import { apiMutate } from '../../api';
import PageLayout from '../components/PageLayout';
import GalleryHeader from '../components/GalleryHeader';
import { useUI } from '../components/UIContext';



export default function SelectionView({ galleryData }: { galleryData: any }) {
    // const navigate = useNavigate();
    const { gallery, photos, isLoading, ratePhoto, size, setSize, isReachingEnd } = galleryData;
    const { user } = useAuth();

    const [ratingFilter, setRatingFilter] = useState<string>('all');
    const filteredPhotos = photos.filter((p: any) => {
        if (ratingFilter === 'rated') return p.rating && Number(p.rating) > 0;
        if (ratingFilter === 'unrated') return p.rating === null || p.rating === undefined;
        if (ratingFilter === '0') return Number(p.rating) === 0;
        if (ratingFilter === '1') return Number(p.rating) === 1;
        if (ratingFilter === '2') return Number(p.rating) === 2;
        if (ratingFilter === '3') return Number(p.rating) === 3;
        if (ratingFilter === '4') return Number(p.rating) === 4;
        if (ratingFilter === '5') return Number(p.rating) === 5;
        return true;
    });

    const { showToast, confirm } = useUI();
    const galleryRef = useRef<HTMLDivElement>(null);
    const [finishing, setFinishing] = useState(false);

    const latestDataRef = useRef({ photos: filteredPhotos, ratePhoto });
    useEffect(() => {
        latestDataRef.current = { photos: filteredPhotos, ratePhoto };
    }, [filteredPhotos, ratePhoto]);

    // Anstatt des ganzen Objekts speichern wir nur die ID, um Bug-freies Live-Update zu garantieren
    const [currentPhotoId, setCurrentPhotoId] = useState<string | null>(null);
    const currentPhotoInLightbox = currentPhotoId ? filteredPhotos.find((p: any) => p.id === currentPhotoId) : null;

    useEffect(() => {
        const handleContextMenu = (e: MouseEvent) => {
            if (e.target instanceof HTMLImageElement) {
                e.preventDefault();
            }
        };
        document.addEventListener('contextmenu', handleContextMenu);
        return () => document.removeEventListener('contextmenu', handleContextMenu);
    }, []);

    usePhotoSwipe({
        galleryRef,
        dependencies: [filteredPhotos.length, user?.id],
        onInit: (lightbox) => {
            lightbox.on('uiRegister', function () {
                if (user) {
                    lightbox.pswp!.ui!.registerElement({
                        name: 'rating-portal-container',
                        order: 10,
                        isButton: false,
                        appendTo: 'wrapper',
                        html: '<div id="rating-portal-anchor" class="absolute bottom-10 left-1/2 -translate-x-1/2 w-full max-w-xl p-4 z-[1000] pointer-events-none flex flex-col justify-end"></div>',
                    });
                }
            });

            lightbox.on('change', () => {
                const currPhotoId = lightbox.pswp!.currSlide?.data?.element?.dataset.photoId;
                setCurrentPhotoId(currPhotoId ? currPhotoId : null);
            });

            lightbox.on('afterInit', () => {
                const currPhotoId = lightbox.pswp!.currSlide?.data?.element?.dataset.photoId;
                setCurrentPhotoId(currPhotoId ? currPhotoId : null);
            });

            lightbox.on('destroy', () => {
                setCurrentPhotoId(null);
            });

            const handleKeyDown = (e: KeyboardEvent) => {
                if (!lightbox?.pswp?.isOpen) return;
                if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;

                const key = parseInt(e.key, 10);
                if (key >= 0 && key <= 5) {
                    const currPhotoId = lightbox.pswp!.currSlide?.data?.element?.dataset.photoId;
                    if (currPhotoId) {
                        const photo = latestDataRef.current.photos.find((p: any) => p.id === currPhotoId);
                        latestDataRef.current.ratePhoto(currPhotoId, key, photo?.comment || '');
                        lightbox.pswp!.next();
                    }
                }
            };
            lightbox.on('beforeOpen', () => {
                document.addEventListener('keydown', handleKeyDown);
            });

            lightbox.on('destroy', () => {
                document.removeEventListener('keydown', handleKeyDown);
            });
        }
    });

    const handleFinishRating = async () => {
        if (!(await confirm({ title: 'Auswahl abschließen?', message: 'Möchtest du deine Auswahl wirklich abschließen? Der Fotograf wird benachrichtigt.', confirmText: 'Abschließen', confirmColor: 'success' }))) return;
        setFinishing(true);
        try {
            await apiMutate('/api/galleries/' + gallery.id + '/finish-rating', 'POST');
            showToast('success', 'Der Fotograf wurde erfolgreich benachrichtigt!');
        } catch {
            showToast('error', 'Fehler beim Senden der Benachrichtigung.');
        }
        setFinishing(false);
    };

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8 relative">
                <GalleryHeader gallery={gallery} breadcrumbs={galleryData.breadcrumbs} />
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h1 className="text-3xl font-bold mb-2">{gallery.name}</h1>
                        <p className="opacity-70">Wähle deine Favoriten aus.</p> 
                
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
                        {user && (
                            <button onClick={handleFinishRating} disabled={finishing} className="btn btn-success text-white">
                                {finishing ? <span className="loading loading-spinner"></span> : <span className="iconify mdi--check-all mr-1"></span>}
                                Auswahl abschließen
                            </button>
                        )}
                    </div>
                </div>

                {user && photos.length > 0 && (
                    <div className="flex justify-center md:justify-start mb-6 overflow-x-auto pb-2">
                        <div className="join">
                            <button className={`btn join-item btn-sm ${ratingFilter === 'all' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('all')}>Alle Bilder</button>
                            <button className={`btn join-item btn-sm ${ratingFilter === 'unrated' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('unrated')}>Neu</button>
                            <button className={`btn join-item btn-sm ${ratingFilter === 'rated' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('rated')}>Favoriten</button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '5' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('5')}>5 <span className="iconify mdi--star text-primary ml-0.5 text-base"></span></button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '4' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('4')}>4 <span className="iconify mdi--star text-primary ml-0.5 text-base"></span></button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '3' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('3')}>3 <span className="iconify mdi--star text-primary ml-0.5 text-base"></span></button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '2' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('2')}>2 <span className="iconify mdi--star text-primary ml-0.5 text-base"></span></button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '1' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('1')}>1 <span className="iconify mdi--star text-primary ml-0.5 text-base"></span></button>
                            <button className={`btn join-item btn-sm ${ratingFilter === '0' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('0')}>Ignoriert</button>
                        </div>
                    </div>
                )}

                {!isLoading && photos.length === 0 && (
                    <div className="py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300 mb-8">
                        <span className="iconify mdi--image-off-outline text-6xl mb-4 text-primary"></span>
                        <h3 className="text-2xl font-bold">Noch keine Bilder vorhanden</h3>
                        <p className="mt-2">Der Fotograf hat noch keine Bilder für diese Galerie freigegeben.</p>
                    </div>
                )}

                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-6" ref={galleryRef}>
                    {filteredPhotos.map((photo: any) => (
                        <div key={photo.id} className="card bg-base-200 shadow-xl overflow-hidden relative group">
                            <a href={photo.url}
                               data-pswp-width={photo.width || 2000}
                               data-pswp-height={photo.height || 1333}
                               data-title={photo.title}
                               data-desc={photo.description}
                               data-artist={photo.artist}
                               data-photo-id={photo.id}
                               className="pswp-item block relative aspect-square">
                                <ResponsiveImage src={photo.thumb_url} srcSet={photo.srcset} containerClassName="absolute inset-0 w-full h-full rounded" className="object-cover w-full h-full select-none" draggable={false} />
                            </a>

                            {user ? <GridPhotoActions photo={photo} ratePhoto={ratePhoto} /> : <div className="card-body p-4 bg-base-100 flex flex-col items-center gap-3"></div>}
                        </div>
                    ))}
                </div>

                {!isReachingEnd && photos.length > 0 && (
                    <div className="text-center mt-8">
                        <button className="btn btn-outline" onClick={() => setSize(size + 1)}>Mehr laden</button>
                    </div>
                )}
            </div>

            {/* REACT PORTAL FÜR PHOTOSWIPE */}
            {currentPhotoInLightbox && document.getElementById('rating-portal-anchor') && (
                createPortal(
                    <DaisyUIRatingBridge photo={currentPhotoInLightbox} ratePhoto={latestDataRef.current.ratePhoto} />,
                    document.getElementById('rating-portal-anchor')!
                )
            )}
        </PageLayout>
    );
}