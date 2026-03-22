import { useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import PageLayout from '../components/PageLayout';

export default function DeliveryView({ galleryData }: { galleryData: any }) {
    const navigate = useNavigate();
    const { gallery, photos, isLoading, totalPhotos, size, setSize, isReachingEnd } = galleryData;
    const galleryRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let lightbox: PhotoSwipeLightbox | null = null;
        if (galleryRef.current && photos.length > 0) {
            lightbox = new PhotoSwipeLightbox({
                gallery: galleryRef.current,
                children: 'a.pswp-item',
                pswpModule: () => import('photoswipe')
            });

            lightbox.on('uiRegister', function () {
                lightbox!.pswp!.ui!.registerElement({
                    name: 'custom-caption', order: 9, isButton: false, appendTo: 'wrapper', html: '',
                    onInit: (el) => {
                        lightbox!.pswp!.on('change', () => {
                            const currSlideElement = lightbox!.pswp!.currSlide?.data?.element;
                            if (currSlideElement) {
                                const title = currSlideElement.getAttribute('data-title') || '';
                                const desc = currSlideElement.getAttribute('data-desc') || '';
                                const artist = currSlideElement.getAttribute('data-artist') || '';

                                el.innerHTML = '';
                                if (title || desc) {
                                    const container = document.createElement('div');
                                    container.className = 'absolute bottom-5 left-5 text-white drop-shadow-md max-w-[600px] font-sans leading-relaxed pointer-events-none p-4';

                                    if (title) {
                                        const b = document.createElement('b');
                                        b.className = 'text-lg block mb-1';
                                        b.textContent = title;
                                        container.appendChild(b);
                                    }
                                    if (desc) {
                                        const span = document.createElement('span');
                                        span.textContent = desc;
                                        container.appendChild(span);
                                    }
                                    if (artist) {
                                        if (desc) container.appendChild(document.createElement('br'));
                                        const small = document.createElement('small');
                                        small.className = 'opacity-80 mt-1 block';
                                        small.textContent = '© ' + artist;
                                        container.appendChild(small);
                                    }
                                    el.appendChild(container);
                                }
                            }
                        });
                    }
                });
            });
            lightbox.init();
        }
        return () => {
            if (lightbox) lightbox.destroy();
        };
    }, [photos.length]);

    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8 relative">
                <button onClick={() => navigate('/')} className="btn btn-sm btn-ghost mb-4">&larr; Zurück zur Übersicht</button>
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h1 className="text-3xl font-bold mb-2">{gallery.name}</h1>
                        <p className="opacity-70">Lade deine Bilder herunter.</p>
                    </div>

                    <div className="flex gap-2">
                        {totalPhotos > 0 && (
                            <button onClick={() => window.open('/api/galleries/' + gallery.id + '/download-zip', '_self')} className="btn btn-primary">
                                Alle herunterladen (.zip)
                            </button>
                        )}
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
                    {photos.map((photo: any) => (
                        <div key={photo.id} className="card bg-base-200 shadow-xl overflow-hidden relative group">
                            <a href={photo.url}
                               data-pswp-width={photo.width || 2000}
                               data-pswp-height={photo.height || 1333}
                               data-title={photo.title}
                               data-desc={photo.description}
                               data-artist={photo.artist}
                               className="pswp-item block relative aspect-square">
                                <img src={photo.thumb_url} className="object-cover w-full h-full select-none" draggable={false} loading="lazy"/>
                            </a>

                            <div className="absolute top-2 right-2 opacity-100">
                                <button onClick={(e) => {
                                    e.preventDefault();
                                    navigate('/photos/' + photo.id);
                                }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
                                    <span className="iconify mdi--information-variant text-lg"></span>
                                </button>
                            </div>

                            <div className="card-body p-4 bg-base-100 flex flex-col gap-3">
                                <button onClick={() => window.open('/api/photos/' + photo.id + '/download', '_self')} className="btn btn-secondary btn-sm">
                                    Einzel-Download
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