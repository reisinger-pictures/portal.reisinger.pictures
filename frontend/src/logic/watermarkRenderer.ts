interface WatermarkCanvasResult {
    canvas: HTMLCanvasElement;
    url: string;
}

function prepareWatermarkCanvas(blob: Blob, opacity: number, size: number): Promise<WatermarkCanvasResult | null> {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(blob);
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                URL.revokeObjectURL(url);
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

            resolve({ canvas, url });
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
}

export const renderSvgToDataUrl = async (blob: Blob, opacity: number, size: number): Promise<string | null> => {
    const result = await prepareWatermarkCanvas(blob, opacity, size);
    if (!result) return null;
    const { canvas, url } = result;
    const dataUrl = canvas.toDataURL('image/png');
    URL.revokeObjectURL(url);
    return dataUrl;
};

export const renderSvgToCanvas = async (blob: Blob, opacity: number, size: number): Promise<Blob | null> => {
    const result = await prepareWatermarkCanvas(blob, opacity, size);
    if (!result) return null;
    const { canvas, url } = result;
    return new Promise((resolve) => {
        canvas.toBlob(resBlob => { resolve(resBlob); URL.revokeObjectURL(url); }, 'image/png');
    });
};
