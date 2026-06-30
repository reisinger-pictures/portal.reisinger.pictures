import {useEffect} from 'react';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {useBillingDetails} from '../../../logic/useLicenseTerms';
import {usePermissions} from '../../../logic/usePermissions';
import {useUI} from '../../components/UIContext';

// IBAN: AT/DE format, basic structural check (country + checksum + alphanumerics), spaces allowed.
const ibanRegex = /^(AT|DE)\d{2}[ ]?(\d{4}[ ]?){4,7}\d{0,4}$/i;

const billingDetailsSchema = z.object({
    bank_holder: z.string().min(2, 'Mindestens 2 Zeichen'),
    bank_iban: z.string().regex(ibanRegex, 'Ungültige IBAN (AT/DE)').max(42),
    bank_bic: z.string().max(12).optional().or(z.literal('')),
    company_street: z.string().min(2, 'Mindestens 2 Zeichen'),
    company_zip: z.string().min(3, 'Mindestens 3 Zeichen'),
    company_city: z.string().min(2, 'Mindestens 2 Zeichen'),
    company_country: z.string().optional().or(z.literal('')),
    company_email: z.string().email('Ungültige E-Mail').optional().or(z.literal('')),
});

type BillingFormValues = z.infer<typeof billingDetailsSchema>;

const EMPTY_DEFAULTS: BillingFormValues = {
    bank_holder: '', bank_iban: '', bank_bic: '',
    company_street: '', company_zip: '', company_city: '',
    company_country: '', company_email: '',
};

/**
 * Bankverbindung & Impressum card. RHF + zod with an explicit "Speichern" button (no per-keystroke
 * PUT, no SWR race). The endpoint stays super_admin-only; the form is read-only for everyone else
 * instead of silently failing on every keystroke.
 */
export default function BillingDetailsCard() {
    const {billingDetails, updateBillingDetails, isLoading} = useBillingDetails();
    const {showToast} = useUI();
    const {isSuperAdmin} = usePermissions();

    const canEdit = isSuperAdmin;

    const {register, handleSubmit, reset, formState: {isSubmitting, errors}} = useForm<BillingFormValues>({
        resolver: zodResolver(billingDetailsSchema),
        defaultValues: EMPTY_DEFAULTS,
    });

    // Hydrate the form once the remote data arrives. This is a load → reset (not a derived-state
    // anti-pattern), triggered by the isLoading/data transition rather than by a user event.
    useEffect(() => {
        if (!isLoading && billingDetails) {
            reset({
                bank_holder: billingDetails.bank_holder ?? '',
                bank_iban: billingDetails.bank_iban ?? '',
                bank_bic: billingDetails.bank_bic ?? '',
                company_street: billingDetails.company_street ?? '',
                company_zip: billingDetails.company_zip ?? '',
                company_city: billingDetails.company_city ?? '',
                company_country: billingDetails.company_country ?? '',
                company_email: billingDetails.company_email ?? '',
            });
        }
    }, [billingDetails, isLoading, reset]);

    const onSubmit = async (data: BillingFormValues) => {
        try {
            await updateBillingDetails(data);
            showToast('success', 'Bankdaten gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern.');
        }
    };

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--bank text-primary text-3xl"></span> Bankverbindung & Impressum
                </h2>
                <p className="text-sm opacity-70 mb-6">
                    Diese Daten werden im Header und Footer deiner PDF-Rechnungen und Lieferscheine angezeigt.
                </p>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Firmenname / Kontoinhaber *</span></label>
                            <input type="text"
                                   className="input input-bordered"
                                   placeholder="Name des Inhabers"
                                   disabled={!canEdit}
                                   {...register('bank_holder')} />
                        </div>

                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Straße & Hausnummer *</span></label>
                            <input type="text"
                                   className="input input-bordered"
                                   placeholder="Musterstraße 1"
                                   disabled={!canEdit}
                                   {...register('company_street')} />
                        </div>

                        <div className="flex gap-4 md:col-span-2 w-full">
                            <div className="form-control w-1/3">
                                <label className="label"><span className="label-text font-bold">PLZ *</span></label>
                                <input type="text"
                                       className="input input-bordered w-full"
                                       placeholder="4020"
                                       disabled={!canEdit}
                                       {...register('company_zip')} />
                            </div>
                            <div className="form-control flex-1">
                                <label className="label"><span className="label-text font-bold">Stadt *</span></label>
                                <input type="text"
                                       className="input input-bordered w-full"
                                       placeholder="Linz"
                                       disabled={!canEdit}
                                       {...register('company_city')} />
                            </div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Land</span></label>
                            <input type="text"
                                   className="input input-bordered"
                                   placeholder="Österreich"
                                   disabled={!canEdit}
                                   {...register('company_country')} />
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail für Rückfragen</span></label>
                            <input type="email"
                                   className="input input-bordered"
                                   placeholder="hello@reisinger.pictures"
                                   disabled={!canEdit}
                                   {...register('company_email')} />
                            {errors.company_email && <span className="text-error text-xs mt-1">{errors.company_email.message}</span>}
                        </div>
                    </div>

                    <div className="divider">Bankdaten</div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">IBAN *</span></label>
                            <input type="text"
                                   className="input input-bordered font-mono"
                                   placeholder="AT..."
                                   disabled={!canEdit}
                                   {...register('bank_iban')} />
                            {errors.bank_iban && <span className="text-error text-xs mt-1">{errors.bank_iban.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">BIC</span></label>
                            <input type="text"
                                   className="input input-bordered font-mono"
                                   placeholder="BIC"
                                   disabled={!canEdit}
                                   {...register('bank_bic')} />
                        </div>
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6 flex items-center gap-4">
                        <button type="submit"
                                disabled={isSubmitting || !canEdit}
                                className="btn btn-primary px-8">
                            {isSubmitting ? <span className="loading loading-spinner loading-sm"></span> : null}
                            Bankdaten speichern
                        </button>
                        {!canEdit && (
                            <span className="text-sm opacity-60">Nur Super-Admins können diese Daten bearbeiten.</span>
                        )}
                    </div>
                </form>
            </div>
        </div>
    );
}
