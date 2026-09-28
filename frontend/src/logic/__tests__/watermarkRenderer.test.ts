import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderSvgToDataUrl, renderSvgToCanvas } from '../watermarkRenderer';

const SVG_SOURCE = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

function createMockBlob(): Blob {
    return new Blob([SVG_SOURCE], { type: 'image/svg+xml' });
}

// --------------------------------------------------------------------------
// CSP harness
//
// Production serves `img-src 'self' data: https:`. jsdom enforces no CSP, so
// this mock enforces that exact policy: a source outside the allowlist is
// refused the way a browser refuses it — `error` fires and the element never
// delivers a decoded image. Without this simulation the render path looks
// healthy in tests while the browser blocks it in production.
// --------------------------------------------------------------------------

const PRODUCTION_IMG_SRC_ALLOWLIST = ['data:', 'https:'] as const;

interface ImageProbe {
    /** Every `src` the renderer assigned to an image element, in order. */
    sources: string[];
    /** Image elements constructed by the renderer. */
    images: unknown[];
    loads: number;
    failures: number;
}

interface MockImage {
    onload: (() => void) | null;
    onerror: ((err?: unknown) => void) | null;
    width: number;
    height: number;
    src: string;
}

/**
 * @param allowlist source prefixes the simulated policy permits. Pass `[]` to
 *        simulate an image that the browser refuses for any reason.
 */
function installCspAwareImage(allowlist: readonly string[] = PRODUCTION_IMG_SRC_ALLOWLIST): ImageProbe {
    const probe: ImageProbe = { sources: [], images: [], loads: 0, failures: 0 };

    vi.stubGlobal('Image', function (this: MockImage) {
        let src = '';
        probe.images.push(this);
        Object.defineProperties(this, {
            width: { value: 50, writable: true },
            height: { value: 50, writable: true },
            src: {
                get: () => src,
                set: (value: string) => {
                    src = value;
                    probe.sources.push(value);
                    setTimeout(() => {
                        if (allowlist.some(prefix => value.startsWith(prefix))) {
                            probe.loads += 1;
                            this.onload?.();
                        } else {
                            probe.failures += 1;
                            this.onerror?.(new Error(`Refused to load "${value}" — blocked by img-src`));
                        }
                    }, 0);
                },
            },
        });
    });

    return probe;
}

let mockCanvas: HTMLCanvasElement;
let mockContext: { drawImage: ReturnType<typeof vi.fn>; globalAlpha: number; fillStyle: string; fillRect: ReturnType<typeof vi.fn> };
/** First argument of every drawImage call — the image that reached the canvas. */
let drawnImages: unknown[];

beforeEach(() => {
    vi.stubGlobal('URL', {
        createObjectURL: vi.fn(() => 'blob:mock'),
        revokeObjectURL: vi.fn(),
    });

    installCspAwareImage();

    drawnImages = [];
    mockContext = {
        drawImage: vi.fn((...args: unknown[]) => {
            drawnImages.push(args[0]);
        }),
        globalAlpha: 1,
        fillStyle: '',
        fillRect: vi.fn(),
    };

    mockCanvas = {
        width: 0,
        height: 0,
        getContext: vi.fn(() => mockContext),
        toDataURL: vi.fn(() => 'data:image/png;base64,mockdata'),
        toBlob: vi.fn((cb: (blob: Blob | null) => void) => {
            cb(new Blob(['mock'], { type: 'image/png' }));
        }),
    } as unknown as HTMLCanvasElement;

    // Only canvases are faked; everything else (jsdom's FileReader included)
    // must keep working on real DOM nodes.
    const realCreateElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
        if (tag === 'canvas') return mockCanvas;
        return realCreateElement(tag) as HTMLElement;
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('watermarkRenderer', () => {
    describe('renderSvgToDataUrl', () => {
        it('renders the file into a data URL the image actually received', async () => {
            const probe = installCspAwareImage();

            const result = await renderSvgToDataUrl(createMockBlob(), 0.5, 100);

            expect(result).toBe('data:image/png;base64,mockdata');
            expect(probe.sources).toHaveLength(1);
            // The bytes travelled intact, not merely behind a data: prefix.
            expect(atob(probe.sources[0].split(',')[1])).toBe(SVG_SOURCE);
        });

        it('assigns no blob: URL, because the production CSP blocks one', async () => {
            const probe = installCspAwareImage();

            await renderSvgToDataUrl(createMockBlob(), 0.5, 100);

            expect(probe.sources.every(source => !source.startsWith('blob:'))).toBe(true);
            expect(URL.createObjectURL).not.toHaveBeenCalled();
            expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        });

        it('delivers the decoded image to the canvas', async () => {
            const probe = installCspAwareImage();

            await renderSvgToDataUrl(createMockBlob(), 0.5, 100);

            expect(probe.loads).toBe(1);
            expect(probe.failures).toBe(0);
            expect(drawnImages).toContain(probe.images[0]);
        });

        it('returns null when the browser refuses the image source', async () => {
            const probe = installCspAwareImage([]);

            const result = await renderSvgToDataUrl(createMockBlob(), 0.5, 100);

            expect(result).toBeNull();
            expect(probe.failures).toBe(1);
        });

        it('returns null when the blob cannot be read at all', async () => {
            vi.stubGlobal('FileReader', class {
                onload: (() => void) | null = null;
                onerror: (() => void) | null = null;
                error = new Error('read failed');
                result: string | null = null;
                readAsDataURL() {
                    this.onerror?.();
                }
            });

            const result = await renderSvgToDataUrl(createMockBlob(), 0.5, 100);

            expect(result).toBeNull();
        });
    });

    describe('renderSvgToCanvas', () => {
        it('produces a Blob without ever creating an object URL', async () => {
            const probe = installCspAwareImage();

            const result = await renderSvgToCanvas(createMockBlob(), 0.5, 100);

            expect(result).toBeInstanceOf(Blob);
            expect(probe.sources.every(source => !source.startsWith('blob:'))).toBe(true);
            expect(URL.createObjectURL).not.toHaveBeenCalled();
            expect(URL.revokeObjectURL).not.toHaveBeenCalled();
        });

        it('returns null when the browser refuses the image source', async () => {
            installCspAwareImage([]);

            const result = await renderSvgToCanvas(createMockBlob(), 0.5, 100);

            expect(result).toBeNull();
        });
    });

    // Guards the harness itself: if this ever passes, the CSP simulation above
    // is toothless and the green tests next to it prove nothing.
    describe('CSP harness', () => {
        it('refuses a blob: URL the way production does', async () => {
            const probe = installCspAwareImage();

            const img = new Image() as MockImage;
            img.src = 'blob:https://portal.reisinger.pictures/6f1c-4b2a';

            await vi.waitFor(() => expect(probe.failures).toBe(1));
            expect(probe.loads).toBe(0);
        });

        it('permits a data: URL the way production does', async () => {
            const probe = installCspAwareImage();

            const img = new Image() as MockImage;
            img.onload = () => {};
            img.src = 'data:image/svg+xml;base64,PHN2Zy8+';

            await vi.waitFor(() => expect(probe.loads).toBe(1));
            expect(probe.failures).toBe(0);
        });
    });
});
