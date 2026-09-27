import { t } from "@lingui/core/macro";
import { useEffect, useId } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Customer } from '../../../api';
import AutocompleteInput from '../../components/AutocompleteInput';
import ModalDialogShell from '../../components/ModalDialogShell';
import { LocationResult } from '../../../logic/useLocations';

const createCustomerSchema = () => z.object({
    name: z.string().min(1, t`Name oder Ansprechpartner ist erforderlich`),
    company: z.string().optional(),
    email: z.string().email(t`Ungültige E-Mail-Adresse`).or(z.literal('')),
    birthdate: z.string().optional(),
    street: z.string().optional(),
    zip: z.string().optional(),
    city: z.string().optional(),
    country: z.string().optional(),
    uid: z.string().optional()
});

type CustomerFormValues = z.infer<ReturnType<typeof createCustomerSchema>>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingCustomer?: Customer | null;
    onSave: (data: Partial<Customer>) => Promise<void>;
}

// Renamed on destructuring, see TextSnippetModal: the record is `customer`, and
// `editing` in this file belongs to the shell's delete-action prop.
export default function CustomerModal({ isOpen, onClose, editingCustomer: customer, onSave }: Props) {
    "use no memo";
    // `useId` is still the source for every field id, for one concrete reason:
    // the regression test asserts that all nine controls carry *distinct* ids
    // and that the two adjacent location comboboxes resolve their listbox and
    // active option through ids derived from their own input id. A single base
    // id keeps those relationships in one namespace, instead of four unrelated
    // ones. The dialog title used to be `${formId}-title` so the hand-rolled
    // `aria-labelledby` could point at it; ModalShell owns that wiring now.
    const formId = useId();
    const nameInputId = `${formId}-name`;
    const companyInputId = `${formId}-company`;
    const emailInputId = `${formId}-email`;
    const birthdateInputId = `${formId}-birthdate`;
    const uidInputId = `${formId}-uid`;
    const streetInputId = `${formId}-street`;
    const zipInputId = `${formId}-zip`;
    const cityInputId = `${formId}-city`;
    const countryInputId = `${formId}-country`;
    const locationGroupLabelId = `${formId}-location-group-label`;
    const customerSchema = createCustomerSchema();
    const { register, handleSubmit, reset, setValue, control, formState: { errors, isSubmitting } } = useForm<CustomerFormValues>({
        resolver: zodResolver(customerSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: customer?.name || '',
                company: customer?.company || '',
                email: customer?.email || '',
                birthdate: customer?.birthdate || '',
                street: customer?.street || '',
                zip: customer?.zip || '',
                city: customer?.city || '',
                country: customer?.country || '',
                uid: customer?.uid || ''
            });
        }
    }, [isOpen, customer, reset]);

    const watchZip = useWatch({ control, name: 'zip' });
    const watchCity = useWatch({ control, name: 'city' });
    const watchCountry = useWatch({ control, name: 'country' });

    const onSubmit = async (data: CustomerFormValues) => {
        try {
            await onSave(data);
            onClose();
        } catch {
            // The parent reports the API error; keep the entered values and modal open.
            return;
        }
    };

    if (!isOpen) return null;

    return (
        <ModalDialogShell
            title={customer ? 'Kunde bearbeiten' : 'Neuen Kunden anlegen'}
            icon="mdi--account-details"
            onClose={onClose}
            // This dialog has no delete action for an existing customer, so the
            // shared footer must not offer one — and the value that would mean
            // "a customer is being edited" is `customer`, not `editing`. See the
            // `editing` note in TextSnippetModal.
            editing={false}
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
            noValidate
            className="z-50"
            // The dialog is `max-w-2xl` today, and it has to stay that width.
            // `maxWidth="2xl"` would render the same class on the same element —
            // both land in the box's class list — so this stays on `boxClassName`,
            // where it has been since before the migration, and the rendered
            // markup is unchanged by either spelling.
            boxClassName="max-w-2xl"
        >
                <div className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label" htmlFor={nameInputId}><span className="label-text font-bold">Name / Ansprechpartner</span></label>
                            <input id={nameInputId} type="text" required {...register('name')} className={`input input-bordered ${errors.name ? 'input-error' : ''}`} />
                            {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label" htmlFor={companyInputId}><span className="label-text font-bold">Firma</span></label>
                            <input id={companyInputId} type="text" {...register('company')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label" htmlFor={emailInputId}><span className="label-text font-bold">E-Mail Adresse</span></label>
                            <input id={emailInputId} type="email" {...register('email')} className={`input input-bordered ${errors.email ? 'input-error' : ''}`} />
                            {errors.email && <span className="text-error text-xs mt-1">{errors.email.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label" htmlFor={birthdateInputId}><span className="label-text font-bold">Geburtsdatum</span></label>
                            <input id={birthdateInputId} type="date" {...register('birthdate')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label" htmlFor={uidInputId}><span className="label-text font-bold">U-ID (Umsatzsteuer-ID)</span></label>
                            <input id={uidInputId} type="text" {...register('uid')} className="input input-bordered" />
                        </div>
                        <div className="form-control md:col-span-2">
                            <label className="label" htmlFor={streetInputId}><span className="label-text font-bold">Straße & Hausnummer</span></label>
                            <input id={streetInputId} type="text" {...register('street')} className="input input-bordered" />
                        </div>
                        <div
                            className="form-control md:col-span-2"
                            role="group"
                            aria-labelledby={locationGroupLabelId}
                        >
                            <div className="label">
                                <span id={locationGroupLabelId} className="label-text font-bold">PLZ & Stadt</span>
                            </div>
                            <div className="flex gap-2">
                                <div className="w-1/3 md:w-32">
                                    <AutocompleteInput<LocationResult>
                                        id={zipInputId}
                                        ariaLabel="PLZ"
                                        value={watchZip || ''}
                                        onChange={val => setValue('zip', val)}
                                        endpoint="/api/search/locations?type=city&q="
                                        mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.postal_code || '', subtitle: loc.name, raw: loc }))}
                                        onSelect={(loc) => {
                                            setValue('city', loc.name);
                                            setValue('zip', loc.postal_code || watchZip || '');
                                            setValue('country', loc.country || watchCountry || '');
                                        }}
                                        placeholder="PLZ"
                                    />
                                </div>
                                <div className="flex-1">
                                    <AutocompleteInput<LocationResult>
                                        id={cityInputId}
                                        ariaLabel="Stadt"
                                        value={watchCity || ''}
                                        onChange={val => setValue('city', val)}
                                        endpoint="/api/search/locations?type=city&q="
                                        mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.name, subtitle: loc.postal_code ? loc.postal_code : '', raw: loc }))}
                                        onSelect={(loc) => {
                                            setValue('city', loc.name);
                                            setValue('zip', loc.postal_code || watchZip || '');
                                            setValue('country', loc.country || watchCountry || '');
                                        }}
                                        placeholder="Stadt"
                                    />
                                </div>
                            </div>
                        </div>
                        <div className="form-control md:col-span-2">
                            <AutocompleteInput<LocationResult>
                                id={countryInputId}
                                label="Land"
                                value={watchCountry || ''}
                                onChange={(val) => setValue('country', val)}
                                endpoint="/api/search/locations?type=country&q="
                                mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.name, subtitle: loc.iso_country || '', raw: loc }))}
                                onSelect={(loc) => setValue('country', loc.name)}
                            />
                        </div>
                    </div>
                </div>
        </ModalDialogShell>
    );
}