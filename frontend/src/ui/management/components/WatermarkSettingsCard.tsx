import {useEffect, useState} from 'react';
import {useSettings} from '../../../logic/useSettings';
import {usePermissions} from '../../../logic/usePermissions';
import {useUI} from '../../components/UIContext';
import {useForm, useWatch} from 'react-hook-form';
import {useBrand} from '../../../logic/useBrand';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';

const watermarkSchema = z.object({
    opacity: z.number().min(0.05).max(1.0)
});

export type WatermarkFormValues = z.infer<typeof watermarkSchema>;

const renderSvgToDataUrl = async (blob: Blob, opacity: number, size: number): Promise<string | null> => {
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

const renderSvgToCanvas = async (blob: Blob, opacity: number, size: number): Promise<Blob | null> => {
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

export default function WatermarkSettingsCard() {
    const {watermark, updateWatermark} = useSettings();
    const {isAdmin} = usePermissions();
    const {showToast} = useUI();
    const {svgUrl} = useBrand();
    
    const [serverSvgBlob, setServerSvgBlob] = useState<Blob | null>(null);
    const [previewRenderedUrl, setPreviewRenderedUrl] = useState<string | null>(null);
    const [isGenerating, setGenerating] = useState(false);

    const {register, handleSubmit, reset, control, setValue, getValues, formState} = useForm<WatermarkFormValues>({
        resolver: zodResolver(watermarkSchema),
        defaultValues: { opacity: 0.15 }
    });

    // Sync server-side watermark opacity into the form (state sync, no side effects).
    useEffect(() => {
        if (watermark) reset({ opacity: watermark.opacity || 0.15 });
    }, [watermark, reset]);

    // Initial data load: fetch the brand SVG blob once and render the initial preview.
    // Preview updates on opacity-change are driven by the slider's onChange handler below.
    useEffect(() => {
        let isActive = true;
        fetch(svgUrl)
            .then(res => res.ok ? res.blob() : null)
            .then(blob => {
                if (isActive && blob) {
                    setServerSvgBlob(blob);
                    renderSvgToDataUrl(blob, getValues('opacity'), 500).then(dataUrl => {
                        if (isActive) setPreviewRenderedUrl(dataUrl);
                    });
                }
            })
            .catch((err) => { console.error('Watermark settings update failed', err); });

        return () => { isActive = false; };
    }, [svgUrl, getValues]);

    const watchOpacity = useWatch({control, name: 'opacity', defaultValue: 0.15});

    const onSubmit = async (data: WatermarkFormValues) => {
        setGenerating(true);
        if (!serverSvgBlob) {
            showToast('error', 'Brand-Logo konnte nicht geladen werden.');
            setGenerating(false);
            return;
        }

        const blob500 = await renderSvgToCanvas(serverSvgBlob, data.opacity, 500);
        const blob1000 = await renderSvgToCanvas(serverSvgBlob, data.opacity, 1000);
        const blob2000 = await renderSvgToCanvas(serverSvgBlob, data.opacity, 2000);
        const selOpacity = data.opacity * 0.3;
        const blob500Sel = await renderSvgToCanvas(serverSvgBlob, selOpacity, 500);
        const blob1000Sel = await renderSvgToCanvas(serverSvgBlob, selOpacity, 1000);
        const blob2000Sel = await renderSvgToCanvas(serverSvgBlob, selOpacity, 2000);

        const fd = new FormData();
        fd.append('opacity', data.opacity.toString());
        fd.append('svg', serverSvgBlob, 'watermark.svg');
        if (blob500) fd.append('bucket_500', blob500, '500.png');
        if (blob1000) fd.append('bucket_1000', blob1000, '1000.png');
        if (blob2000) fd.append('bucket_2000', blob2000, '2000.png');
        if (blob500Sel) fd.append('bucket_500_sel', blob500Sel, '500_sel.png');
        if (blob1000Sel) fd.append('bucket_1000_sel', blob1000Sel, '1000_sel.png');
        if (blob2000Sel) fd.append('bucket_2000_sel', blob2000Sel, '2000_sel.png');

        try {
            await updateWatermark(fd);
            showToast('success', 'Wasserzeichen erfolgreich generiert und gespeichert!');
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setGenerating(false);
    };

    if (!isAdmin) return null;

    return (
        <div className="card bg-base-200 border border-base-300 shadow-sm">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--watermark text-primary text-3xl"></span> Bildschutz
                </h2>
                <p className="text-sm opacity-70 mb-4">
                    Dein Logo wird als Wasserzeichen zentriert über das Originalbild gelegt. Die Größe passt sich dynamisch an (1/3 der Bildbreite).
                    Bei Auswahl-Galerien wird die Deckkraft automatisch reduziert, um die Bildbeurteilung nicht zu stören.
                </p>

                <div className="alert bg-base-100 shadow-sm border border-base-300 mb-8">
                    <span className="iconify mdi--information text-info text-xl"></span>
                    <span>Dein aktuelles Marken-Logo (<strong>{svgUrl}</strong>) wird automatisch als Basis für das Wasserzeichen verwendet.</span>
                </div>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
                    <div className="form-control w-full max-w-xl">
                        <label className="label">
                            <span className="label-text font-bold">Sichtbarkeit (Deckkraft)</span>
                            <span className="label-text-alt font-mono">{Math.round(watchOpacity * 100)} %</span>
                        </label>
                        <input type="range" min="0.05" max="1.0" step="0.05"
                               {...register('opacity', {valueAsNumber: true})}
                               onChange={(e) => {
                                   const nextOpacity = parseFloat(e.target.value);
                                   setValue('opacity', nextOpacity, {shouldDirty: true});
                                   // Re-render the preview as a direct response to the user dragging the slider.
                                   if (serverSvgBlob) {
                                       renderSvgToDataUrl(serverSvgBlob, nextOpacity, 500).then(dataUrl => {
                                           setPreviewRenderedUrl(dataUrl);
                                       });
                                   }
                               }}
                               className="range range-primary"/>
                    </div>

                    <div className="mt-8 border border-base-300 rounded-box overflow-hidden relative h-48 bg-base-300 flex items-center justify-center select-none shadow-inner">
                        <div className="absolute inset-0 opacity-10 bg-[radial-gradient(circle,_var(--color-base-content)_1px,_transparent_1px)] [background-size:20px_20px]"></div>
                        <div className="flex flex-col items-center justify-center pointer-events-none w-1/3 h-full">
                            {previewRenderedUrl ? (
                                <img src={previewRenderedUrl} alt="Watermark Preview" className="w-full h-full object-contain drop-shadow-[0_2px_4px_rgba(0,0,0,0.3)]" />
                            ) : (
                                <span className="opacity-50 font-bold bg-base-100 p-2 rounded flex items-center gap-2"><span className="loading loading-spinner loading-xs"></span> Lade Logo...</span>
                            )}
                        </div>
                        <span className="absolute bottom-2 left-2 text-[10px] font-bold uppercase opacity-40 bg-base-100 px-2 py-0.5 rounded shadow">Live Vorschau (1:1 Render)</span>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting || isGenerating || !serverSvgBlob} className="btn btn-primary px-8">
                            {formState.isSubmitting || isGenerating ? <span className="loading loading-spinner"></span> : 'Wasserzeichen generieren & anwenden'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
