interface WatermarkCanvasResult {
    canvas: HTMLCanvasElement;
}

/**
 * Read the blob into a `data:` URL.
 *
 * The production CSP is `img-src 'self' data: https:` — `blob:` is not on that
 * allowlist. An object URL assigned to an image is therefore refused outright:
 * the element fires `error` and never delivers a decoded image. The policy is
 * owned by a different stack (the running caddy container, not this repo) and
 * already permits `data:`, so the file travels inline instead.
 *
 * A `data:` URL also has no lifetime: no `createObjectURL`/`revokeObjectURL`
 * pair to keep balanced, so the leak fixed in 67fd69c cannot come back here.
 * The input is a small brand SVG, so the ~33% base64 overhead is irrelevant.
 *
 * Returns null when the read itself fails.
 */
function readBlobAsDataUrl(blob: Blob): Promise<string | null> {
    return new Promise(resolve => {
        const reader = new FileReader();
        reader.onload = () => {
            resolve(typeof reader.result === 'string' ? reader.result : null);
        };
        reader.onerror = () => {
            console.error('Watermark renderer: could not read blob as data URL', reader.error);
            resolve(null);
        };
        reader.readAsDataURL(blob);
    });
}

/**
 * Decode the blob into a square, opacity-adjusted canvas.
 *
 * Resolves null for every failure mode (unreadable blob, refused image source,
 * missing 2D context). Callers must treat null as a failure and surface it —
 * returning null on its own is indistinguishable from "still loading" and hides
 * a permanently blocked asset behind a spinner.
 *
 * Contract note: this is module-private. The only callers are
 * `renderSvgToDataUrl` and `renderSvgToCanvas` below; both keep their exported
 * signature.
 */
async function prepareWatermarkCanvas(blob: Blob, opacity: number, size: number): Promise<WatermarkCanvasResult | null> {
    const src = await readBlobAsDataUrl(blob);
    if (src === null) return null;

    return new Promise(resolve => {
        const img = new Image();
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                console.error('Watermark renderer: no 2D canvas context available');
                resolve(null);
                return;
            }

            const scale = Math.min(size / img.width, size / img.height);
            const w = img.width * scale;
            const h = img.height * scale;
            const x = (size - w) / 2;
            const y = (size - h) / 2;

            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = w;
            tempCanvas.height = h;
            const tempCtx = tempCanvas.getContext('2d');
            if (tempCtx) {
                tempCtx.fillStyle = '#ffffff';
                tempCtx.fillRect(0, 0, w, h);
                tempCtx.drawImage(img, 0, 0, w, h);
            }

            ctx.globalAlpha = opacity;
            ctx.drawImage(tempCanvas, x, y, w, h);

            resolve({ canvas });
        };
        img.onerror = () => {
            // Logged because a blocked source otherwise looks identical to a
            // slow one from the outside; the prefix is enough to tell a
            // CSP refusal (`data:` refused, `blob:` attempted) from bad SVG.
            console.error(`Watermark renderer: browser refused image source "${src.slice(0, 40)}…"`);
            resolve(null);
        };
        img.src = src;
    });
}

export const renderSvgToDataUrl = async (blob: Blob, opacity: number, size: number): Promise<string | null> => {
    const result = await prepareWatermarkCanvas(blob, opacity, size);
    if (!result) return null;
    return result.canvas.toDataURL('image/png');
};

export const renderSvgToCanvas = async (blob: Blob, opacity: number, size: number): Promise<Blob | null> => {
    const result = await prepareWatermarkCanvas(blob, opacity, size);
    if (!result) return null;
    return new Promise(resolve => {
        // toBlob reports null itself if the canvas cannot be encoded, so a
        // null here is a real failure and not a placeholder.
        result.canvas.toBlob(resolve, 'image/png');
    });
};
