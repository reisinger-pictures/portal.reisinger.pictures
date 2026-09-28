import { t } from "@lingui/core/macro";
import {useEffect} from 'react';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useUI} from '../../components/UIContext';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {
    CENTS_PER_EURO, DEFAULT_BASE_PRICE, DEFAULT_HOURLY_RATE, DEFAULT_IMAGES_PER_HOUR,
    DEFAULT_OUTDOOR_IMAGES_PER_HOUR, DEFAULT_FLATRATE_MULTIPLIER,
    DEFAULT_SRP_BASE_PRICE, DEFAULT_SRP_SETUP_FEE,
    DEFAULT_SRP_PRIVACY_FEE, DEFAULT_SRP_EXTRA_IMAGE_FEE,
    safeParseFloat, safeParseInt
} from '../../../logic/shootingCalculator';

const createCalculatorSettingsSchema = () => z.object({
    calc_base_price: z.number().min(0, t`Muss positiv sein`),
    calc_hourly_rate: z.number().min(0, t`Muss positiv sein`),
    calc_images_per_hour: z.number().int(t`Muss eine ganze Zahl sein`).min(1, t`Mindestens 1 Bild`),
    calc_outdoor_images_per_hour: z.number().int(t`Muss eine ganze Zahl sein`).min(1, t`Mindestens 1 Bild`),
    calc_flatrate_surcharge: z.number().min(0, t`Muss positiv sein`).max(900, t`Maximal 900%`),
    srp_base_price: z.number().min(0, t`Muss positiv sein`),
    srp_setup_fee: z.number().min(0, t`Muss positiv sein`),
    srp_privacy_fee: z.number().min(0, t`Muss positiv sein`),
    srp_extra_image_fee: z.number().min(0, t`Muss positiv sein`)
});

type CalculatorSettingsFormValues = z.infer<ReturnType<typeof createCalculatorSettingsSchema>>;

/**
 * The slice of `/api/settings/license-terms` this card reads, in the API's own
 * typing: money as cent integers, counts and the surcharge factor as the
 * stored `settings.value` text.
 *
 * Narrower than `LicenseTerms` on purpose — that type's index signature has to
 * admit both, and this card knows which of its nine fields is which. Typing the
 * parameter precisely is what stops a count being read as money (or the other
 * way round) the next time somebody edits this form.
 */
interface CalculatorSettingsTerms {
    calc_base_price?: number;
    calc_hourly_rate?: number;
    calc_images_per_hour?: string;
    calc_outdoor_images_per_hour?: string;
    calc_flatrate_multiplier?: string;
    srp_base_price?: number;
    srp_setup_fee?: number;
    srp_privacy_fee?: number;
    srp_extra_image_fee?: number;
}

/**
 * The API serves every money field in **cents**; this form edits **euros**.
 * Both money groups of this card therefore cross the same boundary, in the
 * same two places — `mapApiToForm` divides, `mapFormToApi` multiplies back with
 * `Math.round`. That symmetry is the point: before the cents decision the
 * `calc_*` fields took the first leg but skipped the second, so one form sent
 * euros for the studio calculator and cents for the flex calculator, and a
 * silent `× 100` in either direction landed in the price of a real quote.
 */
function mapApiToForm(terms: CalculatorSettingsTerms): CalculatorSettingsFormValues {
    const flatrateMultiplier = safeParseFloat(terms.calc_flatrate_multiplier, parseFloat(DEFAULT_FLATRATE_MULTIPLIER));
    return {
        calc_base_price: safeParseFloat(terms.calc_base_price, DEFAULT_BASE_PRICE) / CENTS_PER_EURO,
        calc_hourly_rate: safeParseFloat(terms.calc_hourly_rate, DEFAULT_HOURLY_RATE) / CENTS_PER_EURO,
        calc_images_per_hour: safeParseInt(terms.calc_images_per_hour, DEFAULT_IMAGES_PER_HOUR),
        calc_outdoor_images_per_hour: safeParseInt(terms.calc_outdoor_images_per_hour, parseFloat(DEFAULT_OUTDOOR_IMAGES_PER_HOUR)),
        calc_flatrate_surcharge: Math.round((flatrateMultiplier - 1) * 100),
        srp_base_price: safeParseFloat(terms.srp_base_price, DEFAULT_SRP_BASE_PRICE) / CENTS_PER_EURO,
        srp_setup_fee: safeParseFloat(terms.srp_setup_fee, DEFAULT_SRP_SETUP_FEE) / CENTS_PER_EURO,
        srp_privacy_fee: safeParseFloat(terms.srp_privacy_fee, DEFAULT_SRP_PRIVACY_FEE) / CENTS_PER_EURO,
        srp_extra_image_fee: safeParseFloat(terms.srp_extra_image_fee, DEFAULT_SRP_EXTRA_IMAGE_FEE) / CENTS_PER_EURO,
    };
}

function mapFormToApi(data: CalculatorSettingsFormValues): Record<string, number | string> {
    return {
        calc_base_price: Math.round(data.calc_base_price * CENTS_PER_EURO),
        calc_hourly_rate: Math.round(data.calc_hourly_rate * CENTS_PER_EURO),
        calc_images_per_hour: data.calc_images_per_hour,
        calc_outdoor_images_per_hour: data.calc_outdoor_images_per_hour,
        calc_flatrate_multiplier: 1 + (data.calc_flatrate_surcharge / 100),
        srp_base_price: Math.round(data.srp_base_price * CENTS_PER_EURO),
        srp_setup_fee: Math.round(data.srp_setup_fee * CENTS_PER_EURO),
        srp_privacy_fee: Math.round(data.srp_privacy_fee * CENTS_PER_EURO),
        srp_extra_image_fee: Math.round(data.srp_extra_image_fee * CENTS_PER_EURO),
    };
}

export default function CalculatorSettingsCard() {
    "use no memo";
    const {terms, updateTerms} = useLicenseTerms();
    const {showToast} = useUI();
    const calculatorSettingsSchema = createCalculatorSettingsSchema();

    const {register, handleSubmit, reset, formState: {isSubmitting}} = useForm<CalculatorSettingsFormValues>({
        resolver: zodResolver(calculatorSettingsSchema),
        defaultValues: {
            // The form edits euros; the fallbacks are the cent amounts the
            // calculator itself uses, so they cross the same boundary as the
            // API values above instead of being typed in a second time.
            calc_base_price: DEFAULT_BASE_PRICE / CENTS_PER_EURO,
            calc_hourly_rate: DEFAULT_HOURLY_RATE / CENTS_PER_EURO,
            calc_images_per_hour: DEFAULT_IMAGES_PER_HOUR,
            calc_outdoor_images_per_hour: parseFloat(DEFAULT_OUTDOOR_IMAGES_PER_HOUR), calc_flatrate_surcharge: 20,
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
            showToast('success', t`Kalkulator-Einstellungen gespeichert.`);
        } catch {
            showToast('error', t`Fehler beim Speichern.`);
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
                                className="label-text font-bold">Outdoor-Bilder/Std.</span></label>
                            <div className="join w-full">
                                <input type="number" step="1" min="1" className="input input-bordered join-item w-full" {...register('calc_outdoor_images_per_hour', {valueAsNumber: true})} />
                                <span className="join-badge">Stk</span>
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
