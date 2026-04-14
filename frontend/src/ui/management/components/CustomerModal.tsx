import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { Customer } from '../ManagementCustomersView';
import AutocompleteInput from '../../components/AutocompleteInput';
import { LocationResult } from '../../../logic/useLocations';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingCustomer?: Customer | null;
    onSave: (data: Partial<Customer>) => Promise<void>;
}

export default function CustomerModal({ isOpen, onClose, editingCustomer, onSave }: Props) {
    const { register, handleSubmit, reset, setValue, watch, formState: { isSubmitting } } = useForm<Partial<Customer>>();

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingCustomer?.name || '',
                company: editingCustomer?.company || '',
                email: editingCustomer?.email || '',
                street: editingCustomer?.street || '',
                zip: editingCustomer?.zip || '',
                city: editingCustomer?.city || '',
                country: editingCustomer?.country || '',
                uid: editingCustomer?.uid || ''
            });
        }
    }, [isOpen, editingCustomer, reset]);

    const watchZip = watch('zip');
    const watchCity = watch('city');
    const watchCountry = watch('country');

    const onSubmit = async (data: Partial<Customer>) => {
        await onSave(data);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-2xl relative">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--account-details text-primary"></span>
                    {editingCustomer ? 'Kunde bearbeiten' : 'Neuen Kunden anlegen'}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Name / Ansprechpartner</span></label>
                            <input type="text" {...register('name')} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Firma</span></label>
                            <input type="text" {...register('company')} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail Adresse</span></label>
                            <input type="email" {...register('email')} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">U-ID (Umsatzsteuer-ID)</span></label>
                            <input type="text" {...register('uid')} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Straße & Hausnummer</span></label>
                            <input type="text" {...register('street')} className="input input-sm input-bordered" />
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
                                            setValue('zip', loc.postal_code || watchZip);
                                            setValue('country', loc.country || watchCountry);
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
                                            setValue('zip', loc.postal_code || watchZip);
                                            setValue('country', loc.country || watchCountry);
                                        }}
                                        placeholder="Stadt"
                                    />
                                </div>
                            </div>
                        </div>
                        <div className="form-control">
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

                    <div className="modal-action mt-6">
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
