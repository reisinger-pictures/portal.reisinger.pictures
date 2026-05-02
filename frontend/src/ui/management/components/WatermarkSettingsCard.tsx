import {useEffect, useState} from 'react';
import {useSettings} from '../../../logic/useSettings';
import {useAuth} from '../../../logic/useAuth';
import {useUI} from '../../components/UIContext';
import {useForm, useWatch} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';

const watermarkSchema = z.object({
    opacity: z.number().min(0.05).max(1.0)
});

export type WatermarkFormValues = z.infer<typeof watermarkSchema>;

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
            
            // 1. Temp-Canvas für weißen Hintergrund
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = w;
            tempCanvas.height = h;
            const tempCtx = tempCanvas.getContext('2d');
            if (tempCtx) {
                tempCtx.fillStyle = '#ffffff';
                tempCtx.fillRect(0, 0, w, h);
                tempCtx.drawImage(img, 0, 0, w, h);
            }
            
            // 2. Finales Canvas mit konfigurierter Deckkraft zeichnen
            ctx.globalAlpha = opacity;
            ctx.drawImage(tempCanvas, x, y, w, h);
            
            canvas.toBlob(resBlob => {
                resolve(resBlob);
                URL.revokeObjectURL(url);
            }, 'image/png');
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
};

export default function WatermarkSettingsCard() {
    const {watermark, updateWatermark} = useSettings();
    const {user, mutate: mutateUser} = useAuth();
    const {showToast} = useUI();
    
    const [svgFile, setSvgFile] = useState<File | null>(null);
    const [serverSvgBlob, setServerSvgBlob] = useState<Blob | null>(null);
    const [previewRenderedUrl, setPreviewRenderedUrl] = useState<string | null>(null);
    
    const [isGenerating, setGenerating] = useState(false);

    const {register, handleSubmit, reset, control, formState} = useForm<WatermarkFormValues>({
        resolver: zodResolver(watermarkSchema),
        defaultValues: { opacity: 0.15 }
    });

    useEffect(() => {
        if (watermark) {
            reset({ opacity: watermark.opacity || 0.15 });
            if (watermark.has_svg && !serverSvgBlob) {
                fetch('/api/management/settings/watermark/svg', { credentials: 'include' })
                    .then(res => res.ok ? res.blob() : null)
                    .then(blob => { if (blob) setServerSvgBlob(blob); })
                    .catch(() => {});
            }
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [watermark, reset]);

    const watchOpacity = useWatch({control, name: 'opacity', defaultValue: 0.15});
    const activeBlob = svgFile || serverSvgBlob;

    // Exact 1:1 Preview Rendering
    useEffect(() => {
        let isActive = true;
        if (activeBlob) {
            renderSvgToCanvas(activeBlob, watchOpacity, 500).then(blob => {
                if (!isActive || !blob) return;
                const url = URL.createObjectURL(blob);
                setPreviewRenderedUrl(prev => {
                    if (prev) URL.revokeObjectURL(prev);
                    return url;
                });
            });
        } else {
            setPreviewRenderedUrl(prev => {
                if (prev) URL.revokeObjectURL(prev);
                return null;
            });
        }
        return () => { isActive = false; };
    }, [activeBlob, watchOpacity]);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (previewRenderedUrl) URL.revokeObjectURL(previewRenderedUrl);
        };
    }, [previewRenderedUrl]);

    const onSubmit = async (data: WatermarkFormValues) => {
        setGenerating(true);
        
        if (!activeBlob) {
            showToast('error', 'Bitte lade ein SVG-Logo hoch.');
            setGenerating(false);
            return;
        }

        const blob500 = await renderSvgToCanvas(activeBlob, data.opacity, 500);
        const blob1000 = await renderSvgToCanvas(activeBlob, data.opacity, 1000);
        const blob2000 = await renderSvgToCanvas(activeBlob, data.opacity, 2000);

        const selOpacity = data.opacity * 0.3;
        const blob500Sel = await renderSvgToCanvas(activeBlob, selOpacity, 500);
        const blob1000Sel = await renderSvgToCanvas(activeBlob, selOpacity, 1000);
        const blob2000Sel = await renderSvgToCanvas(activeBlob, selOpacity, 2000);

        const fd = new FormData();
        fd.append('opacity', data.opacity.toString());
        if (svgFile) fd.append('svg', svgFile);
        if (blob500) fd.append('bucket_500', blob500, '500.png');
        if (blob1000) fd.append('bucket_1000', blob1000, '1000.png');
        if (blob2000) fd.append('bucket_2000', blob2000, '2000.png');
        if (blob500Sel) fd.append('bucket_500_sel', blob500Sel, '500_sel.png');
        if (blob1000Sel) fd.append('bucket_1000_sel', blob1000Sel, '1000_sel.png');
        if (blob2000Sel) fd.append('bucket_2000_sel', blob2000Sel, '2000_sel.png');

        try {
            await updateWatermark(fd);
            showToast('success', 'Wasserzeichen-Einstellungen gespeichert');
            setSvgFile(null); // Nach Speichern das lokale File verwerfen und auf Server-Blob stützen
            await mutateUser();
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setGenerating(false);
    };

    if (!user?.is_admin) return null;

    return (
        <div className="card bg-base-200 border border-base-300 shadow-sm">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--watermark text-primary text-3xl"></span> Bildschutz
                </h2>
                <p className="text-sm opacity-70 mb-8">
                    Dein Logo wird als Wasserzeichen zentriert über das Originalbild gelegt. Die Größe passt sich dynamisch an (1/3 der Bildbreite).
                    Bei Auswahl-Galerien wird die Deckkraft automatisch reduziert, um die Bildbeurteilung nicht zu stören.
                </p>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
                    <div className="form-control w-full max-w-md">
                        <label className="label"><span className="label-text font-bold">Logo (nur .svg)</span></label>
                        <input type="file" accept=".svg" onChange={e => {
                            const file = e.target.files?.[0];
                            if (file) setSvgFile(file);
                        }} className="file-input file-input-bordered w-full bg-base-100" />
                        <div className="label"><span className="label-text-alt opacity-70">Optional, falls bereits eines hochgeladen wurde.</span></div>
                    </div>

                    <div className="form-control w-full max-w-xl">
                        <label className="label">
                            <span className="label-text font-bold">Sichtbarkeit (Deckkraft)</span>
                            <span className="label-text-alt font-mono">{Math.round(watchOpacity * 100)} %</span>
                        </label>
                        <input type="range" min="0.05" max="1.0"
                               step="0.05" {...register('opacity', {valueAsNumber: true})}
                               className="range range-primary"/>
                    </div>

                    <div
                        className="mt-8 border border-base-300 rounded-box overflow-hidden relative h-48 bg-base-300 flex items-center justify-center select-none shadow-inner">
                        <div
                            className="absolute inset-0 opacity-10 bg-[radial-gradient(circle,_var(--color-base-content)_1px,_transparent_1px)] [background-size:20px_20px]"></div>
                        <div className="flex flex-col items-center justify-center pointer-events-none w-1/3 h-full">
                            {previewRenderedUrl ? (
                                <img src={previewRenderedUrl} alt="Watermark Preview" className="w-full h-full object-contain drop-shadow-[0_2px_4px_rgba(0,0,0,0.3)]" />
                            ) : (
                                <span className="opacity-50 font-bold bg-base-100 p-2 rounded">Kein Logo vorhanden</span>
                            )}
                        </div>
                        <span
                            className="absolute bottom-2 left-2 text-[10px] font-bold uppercase opacity-40 bg-base-100 px-2 py-0.5 rounded shadow">Live Vorschau (1:1 Render)</span>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting || isGenerating} className="btn btn-primary px-8">
                            {formState.isSubmitting || isGenerating ?
                                <span className="loading loading-spinner"></span> : 'Einstellungen anwenden'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
