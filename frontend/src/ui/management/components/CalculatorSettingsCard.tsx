import {useEffect} from 'react';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useUI} from '../../components/UIContext';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';

const calculatorSettingsSchema = z.object({
    calc_base_price: z.number().min(0, 'Muss positiv sein'),
    calc_hourly_rate: z.number().min(0, 'Muss positiv sein'),
    calc_images_per_hour: z.number().int('Muss eine ganze Zahl sein').min(1, 'Mindestens 1 Bild'),
    atr_base_price: z.number().min(0, 'Muss positiv sein'),
    atr_setup_fee: z.number().min(0, 'Muss positiv sein'),
    atr_privacy_fee: z.number().min(0, 'Muss positiv sein'),
    atr_extra_image_fee: z.number().min(0, 'Muss positiv sein')
});

type CalculatorSettingsFormValues = z.infer<typeof calculatorSettingsSchema>;

export default function CalculatorSettingsCard() {
    const {terms, updateTerms} = useLicenseTerms();
    const {showToast} = useUI();

    const {register, handleSubmit, reset, formState: {isSubmitting}} = useForm<CalculatorSettingsFormValues>({
        resolver: zodResolver(calculatorSettingsSchema),
        defaultValues: {
            calc_base_price: 50, calc_hourly_rate: 80, calc_images_per_hour: 6,
            atr_base_price: 149, atr_setup_fee: 50, atr_privacy_fee: 200, atr_extra_image_fee: 15
        }
    });

    useEffect(() => {
        if (terms) {
            reset({
                calc_base_price: parseFloat(terms.calc_base_price || '50'),
                calc_hourly_rate: parseFloat(terms.calc_hourly_rate || '80'),
                calc_images_per_hour: parseInt(terms.calc_images_per_hour || '6', 10),
                atr_base_price: parseFloat(terms.atr_base_price || '149'),
                atr_setup_fee: parseFloat(terms.atr_setup_fee || '50'),
                atr_privacy_fee: parseFloat(terms.atr_privacy_fee || '200'),
                atr_extra_image_fee: parseFloat(terms.atr_extra_image_fee || '15')
            });
        }
    }, [terms, reset]);

    const onSubmit = async (data: CalculatorSettingsFormValues) => {
        try {
            await updateTerms({
                calc_base_price: data.calc_base_price,
                calc_hourly_rate: data.calc_hourly_rate,
                calc_images_per_hour: data.calc_images_per_hour,
                atr_base_price: data.atr_base_price,
                atr_setup_fee: data.atr_setup_fee,
                atr_privacy_fee: data.atr_privacy_fee,
                atr_extra_image_fee: data.atr_extra_image_fee,
                mult_commercial: terms?.mult_commercial || '2.0',
                mult_unlimited: terms?.mult_unlimited || '1.5',
                mult_international: terms?.mult_international || '1.5'
            });
            showToast('success', 'Kalkulator-Einstellungen gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern.');
        }
    };

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6 md:p-8">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--calculator text-primary text-3xl"></span> Paket-Rechner Konfiguration
                </h2>
                <p className="text-sm opacity-70 mb-6">
                    Definiere die Parameter für den manuellen "Paket-Kalkulator" in Angeboten und Rechnungen.
                </p>
                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="mb-4 font-bold border-b border-base-300 pb-2 text-primary">B2B (Premium Tarif)</div>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Grundpreis (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('calc_base_price', {valueAsNumber: true})} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Stundensatz (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('calc_hourly_rate', {valueAsNumber: true})} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Bilder pro Stunde</span></label>
                            <input type="number" step="1"
                                   className="input input-bordered" {...register('calc_images_per_hour', {valueAsNumber: true})} />
                        </div>
                    </div>

                    <div className="mb-4 font-bold border-b border-base-300 pb-2 text-primary">B2C (Flex-Paket)</div>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Basispreis (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('atr_base_price', {valueAsNumber: true})} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Setup-Fee (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('atr_setup_fee', {valueAsNumber: true})} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Extra-Bild (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('atr_extra_image_fee', {valueAsNumber: true})} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Privacy-Fee (€)</span></label>
                            <input type="number" step="0.01"
                                   className="input input-bordered" {...register('atr_privacy_fee', {valueAsNumber: true})} />
                        </div>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={isSubmitting} className="btn btn-primary px-8">Einstellungen
                            anwenden
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
