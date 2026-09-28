import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useEffect, useRef, useState} from 'react';
import {useSettings} from '../../../logic/useSettings';
import {usePermissions} from '../../../logic/usePermissions';
import {useUI} from '../../components/UIContext';
import {useForm, useWatch} from 'react-hook-form';
import {useBrand} from '../../../logic/useBrand';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {renderSvgToDataUrl, renderSvgToCanvas} from '../../../logic/watermarkRenderer';

const watermarkSchema = z.object({
    opacity: z.number().min(0.05).max(1.0)
});

export type WatermarkFormValues = z.infer<typeof watermarkSchema>;

/**
 * The preview is a live render, so "no URL yet" and "the render failed" are two
 * different facts and must not share one value — a single `string | null` turns
 * a permanently blocked logo into a spinner that never resolves.
 */
type PreviewState = { status: 'loading' } | { status: 'ready'; dataUrl: string } | { status: 'failed' };

/**
 * Render the preview and hand the outcome to `apply`.
 *
 * Module scope on purpose: this component opts out of the React Compiler
 * ("use no memo"), so a component-scoped helper would get a new identity on
 * every render and could not be listed in the effect's dependencies without
 * re-fetching the logo on every render.
 *
 * The renderer resolves null for a refused image source; a rejection is caught
 * here so neither escapes as an unhandled rejection. Both map to 'failed'.
 */
const renderPreview = async (blob: Blob, opacity: number, apply: (result: PreviewState) => void): Promise<void> => {
    let dataUrl: string | null = null;
    try {
        dataUrl = await renderSvgToDataUrl(blob, opacity, 500);
    } catch (err) {
        console.error('Watermark preview render failed', err);
    }
    apply(dataUrl === null ? { status: 'failed' } : { status: 'ready', dataUrl });
};

export default function WatermarkSettingsCard() {
    "use no memo";
    const {watermark, updateWatermark} = useSettings();
    const {isAdmin} = usePermissions();
    const {showToast} = useUI();
    const {svgUrl} = useBrand();
    
    const [serverSvgBlob, setServerSvgBlob] = useState<Blob | null>(null);
    const [previewState, setPreviewState] = useState<PreviewState>({ status: 'loading' });
    const [isGenerating, setGenerating] = useState(false);
    // Renders overlap: the initial load and every slider change start their own.
    // Only the newest result may touch the state, otherwise a slow superseded
    // render can overwrite a good preview — or, worse, raise a failure that no
    // longer describes what is on screen.
    const renderSeqRef = useRef(0);

    const {register, handleSubmit, reset, control, setValue, getValues, formState} = useForm<WatermarkFormValues>({
        resolver: zodResolver(watermarkSchema),
        defaultValues: { opacity: 0.15 }
    });

    // Sync server-side watermark opacity into the form (state sync, no side effects).
    useEffect(() => {
        if (watermark) reset({ opacity: watermark.opacity || 0.15 });
    }, [watermark, reset]);

    // This is a public static brand asset, not a portal API request; it has no
    // session to refresh. Preview updates are driven by the slider handler.
    // No reset to 'loading' here on purpose: the initial state already is
    // 'loading', and on a later run the previous outcome stays until a new one
    // replaces it — the same "keep the current preview while re-rendering" rule
    // the slider follows. A failure always overwrites, so nothing goes stale
    // into a spinner.
    useEffect(() => {
        let isActive = true;

        const load = async () => {
            let blob: Blob | null = null;
            try {
                const res = await fetch(svgUrl);
                if (res.ok) blob = await res.blob();
            } catch (err) {
                console.error('Watermark logo load failed', err);
            }
            if (!isActive) return;
            if (!blob) {
                setServerSvgBlob(null);
                setPreviewState({ status: 'failed' });
                return;
            }

            setServerSvgBlob(blob);
            const seq = ++renderSeqRef.current;
            await renderPreview(blob, getValues('opacity'), result => {
                if (isActive && seq === renderSeqRef.current) setPreviewState(result);
            });
        };
        load();

        return () => { isActive = false; };
    }, [svgUrl, getValues]);

    const watchOpacity = useWatch({control, name: 'opacity', defaultValue: 0.15});

    const onSubmit = async (data: WatermarkFormValues) => {
        setGenerating(true);
        if (!serverSvgBlob) {
            showToast('error', t`Brand-Logo konnte nicht geladen werden.`);
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
            showToast('success', t`Wasserzeichen erfolgreich generiert und gespeichert!`);
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        }
        setGenerating(false);
    };

    if (!isAdmin) return null;

    return (
        <div className="card bg-base-200 border border-base-300 shadow-sm">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--watermark text-primary text-3xl"></span> <Trans>Bildschutz</Trans>
                </h2>
                <p className="text-sm opacity-70 mb-4">
                    <Trans>Dein Logo wird als Wasserzeichen zentriert über das Originalbild gelegt. Die Größe passt sich dynamisch an (1/3 der Bildbreite).
                    Bei Auswahl-Galerien wird die Deckkraft automatisch reduziert, um die Bildbeurteilung nicht zu stören.</Trans>
                </p>

                <div className="alert bg-base-100 shadow-sm border border-base-300 mb-8">
                    <span className="iconify mdi--information text-info text-xl"></span>
                    <span><Trans>Dein aktuelles Marken-Logo (<strong>{svgUrl}</strong>) wird automatisch als Basis für das Wasserzeichen verwendet.</Trans></span>
                </div>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
                    <div className="form-control w-full max-w-xl">
                        <label className="label">
                            <span className="label-text font-bold"><Trans>Sichtbarkeit (Deckkraft)</Trans></span>
                            <span className="label-text-alt font-mono">{Math.round(watchOpacity * 100)} %</span>
                        </label>
                        <input type="range" min="0.05" max="1.0" step="0.05"
                               {...register('opacity', {valueAsNumber: true})}
                               onChange={(e) => {
                                   const nextOpacity = parseFloat(e.target.value);
                                   setValue('opacity', nextOpacity, {shouldDirty: true});
                                   // Re-render the preview as a direct response to the user dragging the slider.
                                   if (serverSvgBlob) {
                                       const seq = ++renderSeqRef.current;
                                       renderPreview(serverSvgBlob, nextOpacity, result => {
                                           if (seq === renderSeqRef.current) setPreviewState(result);
                                       });
                                   }
                               }}
                               className="range range-primary"/>
                    </div>

                    <div className="mt-8 border border-base-300 rounded-box overflow-hidden relative h-48 bg-base-300 flex items-center justify-center select-none shadow-inner" data-testid="watermark-preview">
                        <div className="absolute inset-0 opacity-10 bg-checkerboard"></div>
                        <div className={`flex flex-col items-center justify-center pointer-events-none h-full ${previewState.status === 'failed' ? 'w-full px-4' : 'w-1/3'}`}>
                            {previewState.status === 'ready' ? (
                                <img src={previewState.dataUrl} alt="Watermark Preview" className="w-full h-full object-contain drop-shadow-md" />
                            ) : previewState.status === 'failed' ? (
                                <div className="alert alert-error shadow-sm" role="alert">
                                    <span className="iconify mdi--alert-circle text-xl"></span>
                                    <span className="text-sm font-bold"><Trans>Brand-Logo konnte nicht geladen werden.</Trans></span>
                                </div>
                            ) : (
                                <span className="opacity-50 font-bold bg-base-100 p-2 rounded flex items-center gap-2"><span className="loading loading-spinner loading-xs"></span> <Trans>Lade Logo...</Trans></span>
                            )}
                        </div>
                        <span className="absolute bottom-2 left-2 text-xs font-bold uppercase opacity-40 bg-base-100 px-2 py-0.5 rounded shadow"><Trans>Live Vorschau (1:1 Render)</Trans></span>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting || isGenerating || !serverSvgBlob} className="btn btn-primary px-8">
                            {formState.isSubmitting || isGenerating ? <span className="loading loading-spinner"></span> : <Trans>Wasserzeichen generieren & anwenden</Trans>}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
