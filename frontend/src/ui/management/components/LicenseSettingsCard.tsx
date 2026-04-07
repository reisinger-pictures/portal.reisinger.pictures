import { useEffect } from 'react';
import { useLicenseTerms } from '../../../logic/useLicenseTerms';
import { useUI } from '../../components/UIContext';
import { useForm, useWatch } from 'react-hook-form';
import { usePricing } from '../../../logic/usePricing';

export default function LicenseSettingsCard() {
    const { terms, updateTerms } = useLicenseTerms();
    const { showToast } = useUI();

    const { register, handleSubmit, reset, control, formState } = useForm({
        defaultValues: {
            base_price: 35.00,
            term_editorial: '', term_commercial: '',
            term_1_year: '', term_unlimited: '',
            term_web: '', term_print: '', term_original: ''
        }
    });

    useEffect(() => {
        if (terms) {
            reset({
                base_price: parseFloat(terms.base_price || '35.00'),
                term_editorial: terms.term_editorial || '', term_commercial: terms.term_commercial || '',
                term_1_year: terms.term_1_year || '', term_unlimited: terms.term_unlimited || '',
                term_web: terms.term_web || '', term_print: terms.term_print || '', term_original: terms.term_original || ''
            });
        }
    }, [terms, reset]);

    const watchBasePrice = useWatch({ control, name: 'base_price', defaultValue: 35.00 });
    const { calculateUpgradePrice } = usePricing(watchBasePrice || 0);

    const onSubmit = async (data: any) => {
        try {
            await updateTerms(data);
            showToast('success', 'Lizenzbedingungen gespeichert');
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--license text-primary text-3xl"></span> Lizenzen & Preise
                </h2>
                <p className="text-sm opacity-70 mb-6">Passe die Rechtstexte an, die dem Kunden beim Checkout angezeigt werden, und definiere den Basispreis für Bildverkäufe.</p>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                    <div className="form-control w-full max-w-xs">
                        <label className="label"><span className="label-text font-bold">Basispreis (Netto in €)</span></label>
                        <input type="number" step="0.5" {...register('base_price')} className="input input-bordered w-full font-mono text-lg text-primary font-bold" />
                        <div className="label"><span className="label-text-alt opacity-70">Gilt für Redaktionell, 1 Jahr, Web.</span></div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-base-100 p-4 rounded-box border border-base-300 shadow-inner">
                        <div className="col-span-full font-bold border-b border-base-300 pb-2">Preisvorschau für Kunden ohne Flatrate</div>
                        <div className="text-sm">Web + Redaktionell + 1 Jahr: <strong className="font-mono text-primary">{calculateUpgradePrice('none', 'web', 'editorial', '1_year').toFixed(2)} €</strong></div>
                        <div className="text-sm">Print + Kommerziell + 1 Jahr: <strong className="font-mono text-primary">{calculateUpgradePrice('none', 'print', 'commercial', '1_year').toFixed(2)} €</strong></div>
                        <div className="text-sm">Original + Kommerziell + Unbegrenzt: <strong className="font-mono text-primary">{calculateUpgradePrice('none', 'original', 'commercial', 'unlimited').toFixed(2)} €</strong></div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Nutzung: Redaktionell</span></label><textarea {...register('term_editorial')} className="textarea textarea-bordered h-24" /></div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Nutzung: Kommerziell</span></label><textarea {...register('term_commercial')} className="textarea textarea-bordered h-24" /></div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Dauer: 1 Jahr</span></label><textarea {...register('term_1_year')} className="textarea textarea-bordered h-20" /></div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Dauer: Unbegrenzt</span></label><textarea {...register('term_unlimited')} className="textarea textarea-bordered h-20" /></div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Auflösung: Web</span></label><textarea {...register('term_web')} className="textarea textarea-bordered h-20" /></div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Auflösung: Print</span></label><textarea {...register('term_print')} className="textarea textarea-bordered h-20" /></div>
                        <div className="form-control md:col-span-2"><label className="label"><span className="label-text font-bold">Auflösung: Original</span></label><textarea {...register('term_original')} className="textarea textarea-bordered h-20" /></div>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting} className="btn btn-primary">
                            {formState.isSubmitting ? <span className="loading loading-spinner"></span> : 'Lizenz-Einstellungen speichern'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
