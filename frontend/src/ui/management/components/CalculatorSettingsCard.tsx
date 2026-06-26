import { useEffect } from 'react';
import { useLicenseTerms } from '../../../logic/useLicenseTerms';
import { useUI } from '../../components/UIContext';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const calculatorSettingsSchema = z.object({
    calc_base_price: z.number().min(0, 'Muss positiv sein'),
    calc_hourly_rate: z.number().min(0, 'Muss positiv sein'),
    calc_images_per_hour: z.number().int('Muss eine ganze Zahl sein').min(1, 'Mindestens 1 Bild')
});

type CalculatorSettingsFormValues = z.infer<typeof calculatorSettingsSchema>;

export default function CalculatorSettingsCard() {
    const { terms, updateTerms } = useLicenseTerms();
    const { showToast } = useUI();

    const { register, handleSubmit, reset, formState: { isSubmitting, errors } } = useForm<CalculatorSettingsFormValues>({
        resolver: zodResolver(calculatorSettingsSchema),
        defaultValues: {
            calc_base_price: 50,
            calc_hourly_rate: 80,
            calc_images_per_hour: 6
        }
    });

    useEffect(() => {
        if (terms) {
            reset({
                calc_base_price: parseFloat(terms.calc_base_price || '50'),
                calc_hourly_rate: parseFloat(terms.calc_hourly_rate || '80'),
                calc_images_per_hour: parseInt(terms.calc_images_per_hour || '6', 10)
            });
        }
    }, [terms, reset]);

    const onSubmit = async (data: CalculatorSettingsFormValues) => {
        try {
            await updateTerms({
                calc_base_price: data.calc_base_price,
                calc_hourly_rate: data.calc_hourly_rate,
                calc_images_per_hour: data.calc_images_per_hour,
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
                    <span className="iconify mdi--calculator text-primary text-3xl"></span> Premium Tarif Kalkulator
                </h2>
                <p className="text-sm opacity-70 mb-6">
                    Definiere die Standardwerte für den automatischen Paket-Rechner in den manuellen Angeboten und Rechnungen.
                </p>
                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Grundpreis (€) *</span></label>
                            <input type="number" step="any" min="0" className={`input input-bordered ${errors.calc_base_price ? 'input-error' : ''}`} {...register('calc_base_price', { valueAsNumber: true })} />
                            {errors.calc_base_price && <span className="text-error text-xs mt-1">{errors.calc_base_price.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Stundensatz (€) *</span></label>
                            <input type="number" step="any" min="0" className={`input input-bordered ${errors.calc_hourly_rate ? 'input-error' : ''}`} {...register('calc_hourly_rate', { valueAsNumber: true })} />
                            {errors.calc_hourly_rate && <span className="text-error text-xs mt-1">{errors.calc_hourly_rate.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Bearbeitung Bilder pro Stunde *</span></label>
                            <input type="number" step="1" min="1" className={`input input-bordered ${errors.calc_images_per_hour ? 'input-error' : ''}`} {...register('calc_images_per_hour', { valueAsNumber: true })} />
                            {errors.calc_images_per_hour && <span className="text-error text-xs mt-1">{errors.calc_images_per_hour.message}</span>}
                        </div>
                    </div>
                    <div className="mt-6 border-t border-base-300 pt-6">
                        <button type="submit" disabled={isSubmitting} className="btn btn-primary px-8">
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Einstellungen anwenden'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
