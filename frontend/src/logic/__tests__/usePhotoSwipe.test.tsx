import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { useRef } from 'react';
import { usePhotoSwipe } from '../usePhotoSwipe';
import { TRACKING_EVENTS } from '../tracking';

type Handler = (...args: unknown[]) => void;

const mockClasses = vi.hoisted(() => {
    class MockPswp {
        handlers: Record<string, Handler[]> = {};
        currSlide: { data: { element: HTMLElement } } | null = null;

        ui = {
            registerElement: vi.fn((opts: { onInit: (el: HTMLElement) => void }) => opts.onInit(document.createElement('div'))),
        };

        on = vi.fn((event: string, handler: Handler) => {
            (this.handlers[event] ||= []).push(handler);
        });

        fire(event: string) {
            (this.handlers[event] || []).forEach(h => h());
        }
    }

    class MockLightbox {
        handlers: Record<string, Handler[]> = {};
        pswp: MockPswp | null = null;

        init = vi.fn(() => {
            this.pswp = new MockPswp();
            (this.handlers['uiRegister'] || []).forEach(h => h());
        });

        on = vi.fn((event: string, handler: Handler) => {
            (this.handlers[event] ||= []).push(handler);
        });

        destroy = vi.fn();

        fire(event: string) {
            (this.handlers[event] || []).forEach(h => h());
        }
    }

    return { MockPswp, MockLightbox };
});

vi.mock('photoswipe/lightbox', () => ({
    default: mockClasses.MockLightbox,
}));

let capturedLightbox: InstanceType<typeof mockClasses.MockLightbox> | null = null;

function Harness({ trigger }: { trigger: number }) {
    const ref = useRef<HTMLDivElement>(null);
    usePhotoSwipe({
        galleryRef: ref,
        trigger,
        onInit: (lightbox) => {
            capturedLightbox = lightbox as unknown as InstanceType<typeof mockClasses.MockLightbox>;
        },
    });
    return <div ref={ref} data-testid="gallery" />;
}

describe('usePhotoSwipe tracking', () => {
    beforeEach(() => {
        capturedLightbox = null;
    });

    afterEach(() => {
        delete window.trackEvent;
        vi.restoreAllMocks();
    });

    it('fires photo_swipe_open when the lightbox opens', () => {
        const trackSpy = vi.fn();
        window.trackEvent = trackSpy;

        render(<Harness trigger={3} />);

        capturedLightbox!.fire('afterInit');

        expect(trackSpy).toHaveBeenCalledWith(TRACKING_EVENTS.photo_swipe_open, undefined);
    });

    it('fires photo_view with the current photo id on slide change', () => {
        const trackSpy = vi.fn();
        window.trackEvent = trackSpy;

        render(<Harness trigger={3} />);

        const slide = document.createElement('a');
        slide.dataset.photoId = 'p1';
        slide.setAttribute('data-title', '');
        slide.setAttribute('data-desc', '');
        slide.setAttribute('data-artist', '');
        capturedLightbox!.pswp!.currSlide = { data: { element: slide } };
        capturedLightbox!.pswp!.fire('change');

        expect(trackSpy).toHaveBeenCalledWith(TRACKING_EVENTS.photo_view, { photo_id: 'p1' });
    });

    it('fires photo_view with undefined when the slide has no photo id', () => {
        const trackSpy = vi.fn();
        window.trackEvent = trackSpy;

        render(<Harness trigger={3} />);

        const slide = document.createElement('a');
        capturedLightbox!.pswp!.currSlide = { data: { element: slide } };
        capturedLightbox!.pswp!.fire('change');

        expect(trackSpy).toHaveBeenCalledWith(TRACKING_EVENTS.photo_view, { photo_id: undefined });
    });
});
