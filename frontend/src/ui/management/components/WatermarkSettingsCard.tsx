import { useEffect, useState } from 'react';
import { useSettings } from '../../../logic/useSettings';
import { useAuth } from '../../../logic/useAuth';
import { useUI } from '../../components/UIContext';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const watermarkSchema = z.object({
    scale: z.coerce.number().min(0.05).max(0.5),
    opacity: z.coerce.number().min(0.1).max(1.0),
    position: z.string(),
    svg: z.any().optional()
});
type WatermarkFormValues = z.infer<typeof watermarkSchema>;

export default function WatermarkSettingsCard() {
    const [cacheBuster, setCacheBuster] = useState(0);
    const { watermark, updateWatermark } = useSettings();
    const { user } = useAuth();
    const { showToast } = useUI();

    const { register, handleSubmit, reset, control, formState, setValue } = useForm<WatermarkFormValues>({
        resolver: zodResolver(watermarkSchema),
        defaultValues: { scale: 0.1, opacity: 0.6, position: 'bottom-right' }
    });

    useEffect(() => {
        if (watermark) {
            reset({
                scale: watermark.scale,
                opacity: watermark.opacity,
                position: watermark.position
            });
        }
    }, [watermark, reset]);

    const watchScale = useWatch({ control, name: 'scale', defaultValue: 0.1 });
    const watchOpacity = useWatch({ control, name: 'opacity', defaultValue: 0.6 });

    const onSubmit = async (data: WatermarkFormValues) => {
        const fd = new FormData();
        if (data.svg && data.svg.length > 0) {
            fd.append('svg', data.svg[0]);
        }
        fd.append('scale', data.scale.toString());
        fd.append('opacity', data.opacity.toString());
        fd.append('position', data.position);

        try {
            await updateWatermark(fd);
            showToast('success', 'Wasserzeichen gespeichert');
            setValue('svg', undefined);
            setCacheBuster(prev => prev + 1);
        } catch {
            showToast('error', 'Fehler beim Speichern des Wasserzeichens');
        }
    };

    if (!user?.is_admin) return null;

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4">Wasserzeichen für Gäste</h2>

                {!watermark?.has_svg && (
                    <div className="alert alert-warning shadow-sm mb-6">
                        <span className="iconify mdi--alert text-xl"></span>
                        <span>Es wurde noch kein SVG-Wasserzeichen hochgeladen. Gäste laden Bilder in öffentlichen Galerien derzeit in Originalqualität ohne Wasserzeichen herunter.</span>
                    </div>
                )}
                {watermark?.has_svg && (
                    <div className="flex flex-col md:flex-row gap-6 mb-8 p-4 bg-base-100 rounded-box border border-base-300 shadow-sm items-center">
                        <div className="w-32 h-32 shrink-0 rounded bg-base-300 border border-base-300 overflow-hidden flex items-center justify-center relative shadow-inner" style={{ backgroundImage: 'repeating-conic-gradient(oklch(var(--b3)) 0% 25%, transparent 0% 50%)', backgroundSize: '16px 16px' }}>
                            <img src={`/api/management/settings/watermark/image?t=${cacheBuster}`} alt="Watermark Preview" className="max-w-[6rem] max-h-[6rem] object-contain drop-shadow-md" onError={(e) => e.currentTarget.style.display = 'none'} />
                        </div>
                        <div>
                            <h3 className="font-bold text-success flex items-center gap-2 mb-1">
                                <span className="iconify mdi--check-circle text-xl"></span>
                                SVG Wasserzeichen ist aktiv
                            </h3>
                            <p className="text-sm opacity-70">Das aktuell hinterlegte Wasserzeichen wird für Gäste gerendert. Es wird automatisch basierend auf der längsten Bildseite skaliert.</p>
                        </div>
                    </div>
                )}

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                    <div className="form-control w-full">
                        <label className="label"><span className="label-text font-bold">Logo (nur .svg)</span></label>
                        <input 
                            type="file" 
                            accept=".svg" 
                            {...register('svg')} 
                            className="file-input file-input-bordered w-full"
                        />
                        <div className="label"><span className="label-text-alt opacity-70">Lade eine neue Datei hoch, um das aktuelle Logo zu ersetzen.</span></div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Größe (Skalierung)</span></label>
                            <input 
                                type="range" 
                                min="0.05" max="0.5" step="0.01" 
                                {...register('scale')} 
                                className="range range-primary"
                            />
                            <div className="text-center text-sm mt-2">{Math.round(watchScale * 100)}% der Bildbreite</div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Deckkraft</span></label>
                            <input 
                                type="range" 
                                min="0.1" max="1.0" step="0.05" 
                                {...register('opacity')} 
                                className="range range-primary"
                            />
                            <div className="text-center text-sm mt-2">{Math.round(watchOpacity * 100)}%</div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Position</span></label>
                            <select {...register('position')} className="select select-bordered w-full">
                                <option value="bottom-right">Unten Rechts</option>
                                <option value="bottom-left">Unten Links</option>
                                <option value="top-right">Oben Rechts</option>
                                <option value="top-left">Oben Links</option>
                                <option value="center">Zentriert</option>
                            </select>
                        </div>
                    </div>

                    <div className="mt-6">
                        <button type="submit" disabled={formState.isSubmitting} className="btn btn-primary">
                            {formState.isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern & Cache leeren'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}