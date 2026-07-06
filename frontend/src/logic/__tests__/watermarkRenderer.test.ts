import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderSvgToDataUrl, renderSvgToCanvas } from '../watermarkRenderer';

function createMockBlob(): Blob {
    return new Blob(['<svg xmlns="http://www.w3.org/2000/svg"></svg>'], { type: 'image/svg+xml' });
}

function createImageConstructor(resolve: boolean) {
    return function (this: {
        onload: (() => void) | null;
        onerror: ((err?: unknown) => void) | null;
    }) {
        this.onload = null;
        this.onerror = null;
        Object.defineProperties(this, {
            width: { value: 50, writable: true },
            height: { value: 50, writable: true },
        });
        setTimeout(() => {
            if (resolve) {
                if (this.onload) this.onload();
            } else {
                if (this.onerror) this.onerror(new Error('load failed'));
            }
        }, 0);
    };
}

let mockCanvas: HTMLCanvasElement;
let mockContext: { drawImage: ReturnType<typeof vi.fn>; globalAlpha: number; fillStyle: string; fillRect: ReturnType<typeof vi.fn> };

beforeEach(() => {
    vi.stubGlobal('URL', {
        createObjectURL: vi.fn(() => 'blob:mock'),
        revokeObjectURL: vi.fn(),
    });

    vi.stubGlobal('Image', createImageConstructor(true));

    mockContext = {
        drawImage: vi.fn(),
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

    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
        if (tag === 'canvas') return mockCanvas;
        return { tag } as unknown as HTMLElement;
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('watermarkRenderer', () => {
    describe('renderSvgToDataUrl', () => {
        it('returns a data URL string for a valid blob', async () => {
            const result = await renderSvgToDataUrl(createMockBlob(), 0.5, 100);
            expect(result).toBe('data:image/png;base64,mockdata');
            expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
            expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
        });

        it('returns null when Image errors', async () => {
            vi.stubGlobal('Image', createImageConstructor(false));
            const result = await renderSvgToDataUrl(createMockBlob(), 0.5, 100);
            expect(result).toBeNull();
        });
    });

    describe('renderSvgToCanvas', () => {
        it('returns a Blob for a valid blob', async () => {
            const result = await renderSvgToCanvas(createMockBlob(), 0.5, 100);
            expect(result).toBeInstanceOf(Blob);
            expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
            expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
        });

        it('returns null when Image errors', async () => {
            vi.stubGlobal('Image', createImageConstructor(false));
            const result = await renderSvgToCanvas(createMockBlob(), 0.5, 100);
            expect(result).toBeNull();
        });
    });
});
