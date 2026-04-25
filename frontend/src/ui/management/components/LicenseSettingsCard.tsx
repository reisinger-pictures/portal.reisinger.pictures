import {useEffect} from 'react';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useUI} from '../../components/UIContext';
import {useForm, useWatch} from 'react-hook-form';
import {usePricing} from '../../../logic/usePricing';

export default function LicenseSettingsCard() {
    const {terms, updateTerms, isLoading} = useLicenseTerms();
    const {showToast} = useUI();

    const {register, handleSubmit, reset, control, formState} = useForm({
        defaultValues: {
            base_price: 35.00,
            mult_commercial: 100,
            mult_international: 50,
            mult_unlimited: 50,
            price_original: 450,
            price_print: 145,
            price_web: 75,
            term_1_year: '',
            term_commercial: '',
            term_editorial: '',
            term_original: '',
            term_print: '',
            term_territory_international: '',
            term_territory_national: '',
            term_unlimited: '',
            term_web: ''
        }
    });

    useEffect(() => {
        if (terms) {
            reset({
                base_price: parseInt(terms.base_price || '3500', 10) / 100,
                term_editorial: terms.editorial || '',
                term_commercial: terms.commercial || '',
                term_1_year: terms['1_year'] || '',
                term_unlimited: terms.unlimited || '',
                term_territory_national: terms.territory_national || '',
                term_territory_international: terms.territory_international || '',
                mult_commercial: ((parseFloat(terms.mult_commercial || '2.0') - 1) * 100),
                mult_unlimited: ((parseFloat(terms.mult_unlimited || '1.5') - 1) * 100),
                mult_international: ((parseFloat(terms.mult_international || '1.5') - 1) * 100),
                term_web: terms.web || '',
                term_print: terms.print || '',
                term_original: terms.original || ''
            });
        }
    }, [terms, reset]);

    // Live-Vorschau mit den aktuell getippten Werten (zurückgerechnet als Multiplikator)
    const watchPriceWeb = useWatch({control, name: 'price_web', defaultValue: 75});
    const watchPricePrint = useWatch({control, name: 'price_print', defaultValue: 145});
    const watchPriceOriginal = useWatch({control, name: 'price_original', defaultValue: 450});
    const watchMultCom = useWatch({control, name: 'mult_commercial', defaultValue: 100});
    const watchMultUnl = useWatch({control, name: 'mult_unlimited', defaultValue: 50});
    const watchMultInt = useWatch({control, name: 'mult_international', defaultValue: 50});

    // Mock-Terms Objekt für die Live-Berechnung in der UI
    const {calculateUpgradePrice} = usePricing({
        price_web: String(watchPriceWeb),
        price_print: String(watchPricePrint),
        price_original: String(watchPriceOriginal),
        mult_commercial: String((watchMultCom / 100) + 1),
        mult_unlimited: String((watchMultUnl / 100) + 1),
        mult_international: String((watchMultInt / 100) + 1)
    });

    if (isLoading) return <div
        className="card bg-base-200 border border-base-300 p-10 flex items-center justify-center min-h-[300px]"><span
        className="loading loading-spinner text-primary"></span></div>;

    const onSubmit = async (data: Record<string, string | number>) => {
        data.base_price = Math.round(Number(data.base_price) * 100);
        // Konvertiere die UI-Prozente (z.B. +100%) zurück in Multiplikatoren (z.B. 2.0)
        data.mult_commercial = (parseFloat(String(data.mult_commercial)) / 100) + 1;
        data.mult_unlimited = (parseFloat(String(data.mult_unlimited)) / 100) + 1;
        data.mult_international = (parseFloat(String(data.mult_international)) / 100) + 1;
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
                <p className="text-sm opacity-70 mb-6">Passe die Rechtstexte an, die dem Kunden beim Checkout angezeigt
                    werden, und definiere den Basispreis für Bildverkäufe.</p>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                    <div className="form-control w-full max-w-xs">
                        <label className="label"><span
                            className="label-text font-bold">Basispreis (Netto in €)</span></label>
                        <input type="number" step="0.5" {...register('base_price')}
                               className="input input-bordered w-full font-mono text-lg text-primary font-bold"/>
                        <div className="label"><span className="label-text-alt opacity-70">Gilt für Redaktionell, 1 Jahr, Web.</span>
                        </div>
                    </div>

                    <div
                        className="grid grid-cols-1 md:grid-cols-2 gap-6 bg-base-100 p-4 rounded-box border border-base-300 shadow-inner">
                        <div className="col-span-full font-bold border-b border-base-300 pb-2">Preisvorschau für Kunden
                            ohne Flatrate
                        </div>
                        <div className="text-sm">Web + Redaktionell + 1 Jahr: <strong
                            className="font-mono text-primary">{calculateUpgradePrice('none', 'web', 'editorial', '1_year').toFixed(2)} €</strong>
                        </div>
                        <div className="text-sm">Print + Kommerziell + 1 Jahr: <strong
                            className="font-mono text-primary">{calculateUpgradePrice('none', 'print', 'commercial', '1_year').toFixed(2)} €</strong>
                        </div>
                        <div className="text-sm">Original + Kommerziell + Unbegrenzt + Weltweit: <strong
                            className="font-mono text-primary">{calculateUpgradePrice('none', 'original', 'commercial', 'unlimited', 'international').toFixed(2)} €</strong>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Nutzung: Redaktionell</span></label><textarea {...register('term_editorial')}
                                                                                                                                                                    className="textarea textarea-bordered h-24"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Nutzung: Kommerziell</span></label><textarea {...register('term_commercial')}
                                                                                                                                                                   className="textarea textarea-bordered h-24"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Dauer: 1 Jahr</span></label><textarea {...register('term_1_year')}
                                                                                                                                                            className="textarea textarea-bordered h-20"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Dauer: Unbegrenzt</span></label><textarea {...register('term_unlimited')}
                                                                                                                                                                className="textarea textarea-bordered h-20"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Auflösung: Web</span></label><textarea {...register('term_web')}
                                                                                                                                                             className="textarea textarea-bordered h-20"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">Auflösung: Print</span></label><textarea {...register('term_print')}
                                                                                                                                                               className="textarea textarea-bordered h-20"/>
                        </div>
                        <div className="form-control md:col-span-2"><label className="label"><span
                            className="label-text font-bold">Auflösung: Original</span></label><textarea {...register('term_original')}
                                                                                                         className="textarea textarea-bordered h-20"/>
                        </div>

                        <div
                            className="col-span-full font-bold border-b border-base-300 pb-2 mt-4 text-primary">Verbreitungsraum
                            (Gebiet)
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">National (Inland)</span></label><textarea {...register('term_territory_national')}
                                                                                                                                                                className="textarea textarea-bordered h-20"/>
                        </div>
                        <div className="form-control"><label className="label"><span className="label-text font-bold">International (Weltweit)</span></label><textarea {...register('term_territory_international')}
                                                                                                                                                                       className="textarea textarea-bordered h-20"/>
                        </div>

                        <div
                            className="col-span-full font-bold border-b border-base-300 pb-2 mt-4 text-primary">Preis-Faktoren
                            (Zuschläge in %)
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Aufschlag: Kommerziell</span></label>
                            <label className="input input-bordered flex items-center gap-2"><input
                                type="number" {...register('mult_commercial')} className="grow"/><span>%</span></label>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Aufschlag: Unbegrenzte Dauer</span></label>
                            <label className="input input-bordered flex items-center gap-2"><input
                                type="number" {...register('mult_unlimited')} className="grow"/><span>%</span></label>
                        </div>
                        <div className="form-control md:col-span-2">
                            <label className="label"><span
                                className="label-text font-bold">Aufschlag: Weltweit</span></label>
                            <label className="input input-bordered flex items-center gap-2"><input
                                type="number" {...register('mult_international')}
                                className="grow"/><span>%</span></label>
                        </div>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={formState.isSubmitting} className="btn btn-primary">
                            {formState.isSubmitting ?
                                <span className="loading loading-spinner"></span> : 'Lizenz-Einstellungen speichern'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
