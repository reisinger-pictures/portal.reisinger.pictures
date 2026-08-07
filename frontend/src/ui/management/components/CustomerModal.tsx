import { t } from "@lingui/core/macro";
import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Customer } from '../../../api';
import AutocompleteInput from '../../components/AutocompleteInput';
import { LocationResult } from '../../../logic/useLocations';

const customerSchema = z.object({
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

type CustomerFormValues = z.infer<typeof customerSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingCustomer?: Customer | null;
    onSave: (data: Partial<Customer>) => Promise<void>;
}

export default function CustomerModal({ isOpen, onClose, editingCustomer, onSave }: Props) {
    "use no memo";
    const { register, handleSubmit, reset, setValue, control, formState: { errors, isSubmitting } } = useForm<CustomerFormValues>({
        resolver: zodResolver(customerSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingCustomer?.name || '',
                company: editingCustomer?.company || '',
                email: editingCustomer?.email || '',
                birthdate: editingCustomer?.birthdate || '',
                street: editingCustomer?.street || '',
                zip: editingCustomer?.zip || '',
                city: editingCustomer?.city || '',
                country: editingCustomer?.country || '',
                uid: editingCustomer?.uid || ''
            });
        }
    }, [isOpen, editingCustomer, reset]);

    const watchZip = useWatch({ control, name: 'zip' });
    const watchCity = useWatch({ control, name: 'city' });
    const watchCountry = useWatch({ control, name: 'country' });

    const onSubmit = async (data: CustomerFormValues) => {
        await onSave(data);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-2xl relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--account-details text-primary"></span>
                    {editingCustomer ? 'Kunde bearbeiten' : 'Neuen Kunden anlegen'}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Name / Ansprechpartner</span></label>
                            <input type="text" {...register('name')} className={`input input-bordered ${errors.name ? 'input-error' : ''}`} />
                            {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Firma</span></label>
                            <input type="text" {...register('company')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail Adresse</span></label>
                            <input type="email" {...register('email')} className={`input input-bordered ${errors.email ? 'input-error' : ''}`} />
                            {errors.email && <span className="text-error text-xs mt-1">{errors.email.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Geburtsdatum</span></label>
                            <input type="date" {...register('birthdate')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">U-ID (Umsatzsteuer-ID)</span></label>
                            <input type="text" {...register('uid')} className="input input-bordered" />
                        </div>
                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Straße & Hausnummer</span></label>
                            <input type="text" {...register('street')} className="input input-bordered" />
                        </div>
                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">PLZ & Stadt</span></label>
                            <div className="flex gap-2">
                                <div className="w-1/3 md:w-32">
                                    <AutocompleteInput<LocationResult>
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
                                label="Land"
                                value={watchCountry || ''}
                                onChange={(val) => setValue('country', val)}
                                endpoint="/api/search/locations?type=country&q="
                                mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.name, subtitle: loc.iso_country || '', raw: loc }))}
                                onSelect={(loc) => setValue('country', loc.name)}
                            />
                        </div>
                    </div>

                    <div className="modal-action col-span-full mt-6">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}