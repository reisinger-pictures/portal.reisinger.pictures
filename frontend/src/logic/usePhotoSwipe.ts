import { useEffect, useRef } from 'react';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

interface UsePhotoSwipeOptions {
    galleryRef: React.RefObject<HTMLElement | null>;
    dependencies: any[];
    onInit?: (lightbox: PhotoSwipeLightbox) => void;
}

export function usePhotoSwipe({ galleryRef, dependencies, onInit }: UsePhotoSwipeOptions) {
    const lightboxRef = useRef<PhotoSwipeLightbox | null>(null);

    useEffect(() => {
        let lightbox: PhotoSwipeLightbox | null = null;
        
        // Prüfe ob Items vorhanden sind (Annahme: dependencies[0] ist die Array-Länge)
        const hasItems = typeof dependencies[0] === 'number' ? dependencies[0] > 0 : true;

        if (galleryRef.current && hasItems) {
            lightbox = new PhotoSwipeLightbox({
                gallery: galleryRef.current,
                children: 'a.pswp-item',
                pswpModule: () => import('photoswipe'),
                arrowKeys: true,
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

            if (onInit) {
                onInit(lightbox);
            }

            lightbox.init();
            lightboxRef.current = lightbox;
        }
        
        return () => {
            if (lightbox) {
                lightbox.destroy();
                lightboxRef.current = null;
            }
        };
    }, dependencies);

    return lightboxRef;
}
