import { t } from "@lingui/core/macro";
import {useEffect} from 'react';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useUI} from '../../components/UIContext';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {
    DEFAULT_BASE_PRICE, DEFAULT_HOURLY_RATE, DEFAULT_IMAGES_PER_HOUR,
    DEFAULT_OUTDOOR_MULTIPLIER, DEFAULT_FLATRATE_MULTIPLIER,
    DEFAULT_SRP_BASE_PRICE, DEFAULT_SRP_SETUP_FEE,
    DEFAULT_SRP_PRIVACY_FEE, DEFAULT_SRP_EXTRA_IMAGE_FEE,
    safeParseFloat, safeParseInt
} from '../../../logic/shootingCalculator';

const calculatorSettingsSchema = z.object({
    calc_base_price: z.number().min(0, t`Muss positiv sein`),
    calc_hourly_rate: z.number().min(0, t`Muss positiv sein`),
    calc_images_per_hour: z.number().int(t`Muss eine ganze Zahl sein`).min(1, t`Mindestens 1 Bild`),
    calc_outdoor_multiplier: z.number().min(10, t`Mindestens 10%`).max(100, t`Maximal 100%`),
    calc_flatrate_surcharge: z.number().min(0, t`Muss positiv sein`).max(900, t`Maximal 900%`),
    srp_base_price: z.number().min(0, t`Muss positiv sein`),
    srp_setup_fee: z.number().min(0, t`Muss positiv sein`),
    srp_privacy_fee: z.number().min(0, t`Muss positiv sein`),
    srp_extra_image_fee: z.number().min(0, t`Muss positiv sein`)
});

type CalculatorSettingsFormValues = z.infer<typeof calculatorSettingsSchema>;

function mapApiToForm(terms: { [key: string]: string | undefined }): CalculatorSettingsFormValues {
    const flatrateMultiplier = safeParseFloat(terms.calc_flatrate_multiplier, parseFloat(DEFAULT_FLATRATE_MULTIPLIER));
    return {
        calc_base_price: safeParseFloat(terms.calc_base_price, DEFAULT_BASE_PRICE),
        calc_hourly_rate: safeParseFloat(terms.calc_hourly_rate, DEFAULT_HOURLY_RATE),
        calc_images_per_hour: safeParseInt(terms.calc_images_per_hour, DEFAULT_IMAGES_PER_HOUR),
        calc_outdoor_multiplier: safeParseFloat(terms.calc_outdoor_multiplier, parseFloat(DEFAULT_OUTDOOR_MULTIPLIER)) * 100,
        calc_flatrate_surcharge: Math.round((flatrateMultiplier - 1) * 100),
        srp_base_price: safeParseFloat(terms.srp_base_price, DEFAULT_SRP_BASE_PRICE) / 100,
        srp_setup_fee: safeParseFloat(terms.srp_setup_fee, DEFAULT_SRP_SETUP_FEE) / 100,
        srp_privacy_fee: safeParseFloat(terms.srp_privacy_fee, DEFAULT_SRP_PRIVACY_FEE) / 100,
        srp_extra_image_fee: safeParseFloat(terms.srp_extra_image_fee, DEFAULT_SRP_EXTRA_IMAGE_FEE) / 100,
    };
}

function mapFormToApi(data: CalculatorSettingsFormValues): Record<string, number | string> {
    return {
        calc_base_price: data.calc_base_price,
        calc_hourly_rate: data.calc_hourly_rate,
        calc_images_per_hour: data.calc_images_per_hour,
        calc_outdoor_multiplier: data.calc_outdoor_multiplier / 100,
        calc_flatrate_multiplier: 1 + (data.calc_flatrate_surcharge / 100),
        srp_base_price: Math.round(data.srp_base_price * 100),
        srp_setup_fee: Math.round(data.srp_setup_fee * 100),
        srp_privacy_fee: Math.round(data.srp_privacy_fee * 100),
        srp_extra_image_fee: Math.round(data.srp_extra_image_fee * 100),
    };
}

export default function CalculatorSettingsCard() {
    const {terms, updateTerms} = useLicenseTerms();
    const {showToast} = useUI();

    const {register, handleSubmit, reset, formState: {isSubmitting}} = useForm<CalculatorSettingsFormValues>({
        resolver: zodResolver(calculatorSettingsSchema),
        defaultValues: {
            calc_base_price: DEFAULT_BASE_PRICE, calc_hourly_rate: DEFAULT_HOURLY_RATE,
            calc_images_per_hour: DEFAULT_IMAGES_PER_HOUR,
            calc_outdoor_multiplier: 50, calc_flatrate_surcharge: 20,
            srp_base_price: DEFAULT_SRP_BASE_PRICE, srp_setup_fee: DEFAULT_SRP_SETUP_FEE,
            srp_privacy_fee: DEFAULT_SRP_PRIVACY_FEE, srp_extra_image_fee: DEFAULT_SRP_EXTRA_IMAGE_FEE
        }
    });

    useEffect(() => {
        if (terms) {
            reset(mapApiToForm(terms));
        }
    }, [terms, reset]);

    const onSubmit = async (data: CalculatorSettingsFormValues) => {
        try {
            await updateTerms({
                ...mapFormToApi(data),
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
                    <div className="mb-4 font-bold border-b border-base-300 pb-2 text-primary">Standard Tarif</div>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Grundpreis</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('calc_base_price', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Stundensatz</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('calc_hourly_rate', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Bilder pro Stunde</span></label>
                            <div className="join w-full">
                                <input type="number" step="1" min="1" className="input input-bordered join-item w-full" {...register('calc_images_per_hour', {valueAsNumber: true})} />
                                <span className="join-badge">Stk</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Outdoor-Faktor</span></label>
                            <div className="join w-full">
                                <input type="number" step="5" min="10" max="100" className="input input-bordered join-item w-full" {...register('calc_outdoor_multiplier', {valueAsNumber: true})} />
                                <span className="join-badge">%</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Reportage-Aufschlag</span></label>
                            <div className="join w-full">
                                <input type="number" step="1" min="0" max="900" className="input input-bordered join-item w-full" {...register('calc_flatrate_surcharge', {valueAsNumber: true})} />
                                <span className="join-badge">%</span>
                            </div>
                        </div>
                    </div>

                    <div className="mb-4 font-bold border-b border-base-300 pb-2 text-primary">Flex Tarif</div>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Basispreis</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('srp_base_price', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Setup-Fee</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('srp_setup_fee', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Extra-Bild</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('srp_extra_image_fee', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Privacy-Fee</span></label>
                            <div className="join w-full">
                                <input type="number" step="0.01" className="input input-bordered join-item w-full" {...register('srp_privacy_fee', {valueAsNumber: true})} />
                                <span className="join-badge">€</span>
                            </div>
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
