import { useEffect, useState } from 'react';
import { useSettings } from '../../../logic/useSettings';
import { useAuth } from '../../../logic/useAuth';
import { useUI } from '../../components/UIContext';
import { useForm, useWatch } from 'react-hook-form';

export default function WatermarkSettingsCard() {
    const [cacheBuster, setCacheBuster] = useState(0);
    const { watermark, updateWatermark } = useSettings();
    const { user } = useAuth();
    const { showToast } = useUI();

    const { register, handleSubmit, reset, control, formState, setValue } = useForm({
        defaultValues: { text: 'reisinger.pictures', opacity: 0.15, svg: undefined }
    });

    useEffect(() => {
        if (watermark) {
            reset({
                text: watermark.text || 'reisinger.pictures',
                opacity: watermark.opacity || 0.15,
                svg: undefined
            });
        }
    }, [watermark, reset]);

    const watchOpacity = useWatch({ control, name: 'opacity', defaultValue: 0.15 });

    const onSubmit = async (data: any) => {
        const fd = new FormData();
        if (data.svg && data.svg.length > 0) {
            fd.append('svg', data.svg[0]);
        }
        fd.append('text', data.text);
        fd.append('opacity', data.opacity.toString());

        try {
            await updateWatermark(fd);
            showToast('success', 'Wasserzeichen gespeichert');
            setValue('svg', undefined);
            setCacheBuster(prev => prev + 1);
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    if (!user?.is_admin) return null;

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="mdi--watermark text-primary text-3xl"></span> Bildschutz (Wasserzeichen)
                </h2>
                <p className="text-sm opacity-70 mb-6">Das Wasserzeichen wird als wiederholendes Muster (Kachel) diagonal über das gesamte Bild gelegt. Bei Auswahl-Galerien (Ratings) wird die Deckkraft automatisch auf 30% reduziert, um nicht zu stören.</p>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="form-control w-full">
                            <label className="label"><span className="label-text font-bold">Logo (Optional, nur .svg)</span></label>
                            <input type="file" accept=".svg" {...register('svg')} className="file-input file-input-bordered w-full" />
                            <div className="label"><span className="label-text-alt opacity-70">Wird mittig in der Kachel platziert.</span></div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Zusatz-Text (Optional)</span></label>
                            <input type="text" {...register('text')} className="input input-bordered w-full" placeholder="z.B. reisinger.pictures" />
                        </div>
                    </div>

                    <div className="form-control w-full max-w-xl">
                        <label className="label"><span className="label-text font-bold">Basis-Deckkraft (Delivery-Galerien)</span></label>
                        <input type="range" min="0.05" max="0.5" step="0.05" {...register('opacity')} className="range range-primary" />
                        <div className="text-sm mt-2 opacity-70 font-mono">{Math.round(watchOpacity * 100)} %</div>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting} className="btn btn-primary">
                            {formState.isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern & Cache leeren'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
