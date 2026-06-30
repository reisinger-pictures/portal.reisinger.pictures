/**
 * Renders an SVG blob to a data URL at a given opacity and canvas size.
 * Used for live preview in the watermark settings UI.
 */
export const renderSvgToDataUrl = async (blob: Blob, opacity: number, size: number): Promise<string | null> => {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(blob);
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            if (!ctx) return resolve(null);

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

            resolve(canvas.toDataURL('image/png'));
            URL.revokeObjectURL(url);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
        img.src = url;
    });
};

/**
 * Renders an SVG blob to a PNG Blob at a given opacity and canvas size.
 * Used for server-side watermark generation (downstream upload).
 */
export const renderSvgToCanvas = async (blob: Blob, opacity: number, size: number): Promise<Blob | null> => {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(blob);
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            if (!ctx) return resolve(null);

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

            canvas.toBlob(resBlob => { resolve(resBlob); URL.revokeObjectURL(url); }, 'image/png');
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
        img.src = url;
    });
};
