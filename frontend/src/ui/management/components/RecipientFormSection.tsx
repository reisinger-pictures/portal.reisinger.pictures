import AutocompleteInput from '../../components/AutocompleteInput';
import { Customer } from '../../../api';
import { LocationResult } from '../../../logic/useLocations';
import { DocumentFormData } from '../ManagementManualInvoiceView';

interface Props {
    formData: DocumentFormData;
    onUpdate: (field: string, value: string) => void;
    onMultiUpdate: (updates: RecipientFormUpdates) => void;
}

export interface RecipientFormUpdates {
    [key: string]: string;
}

export default function RecipientFormSection({ formData, onUpdate, onMultiUpdate }: Props) {
    return (
        <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
            <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4">Rechnungsempfänger</h2>
            <p className="text-sm opacity-60 mb-4">Suche nach Kunden (CRM) oder gib die Daten manuell ein.</p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 *:md:odd:last:col-span-2">
                <AutocompleteInput<Customer>
                    label="Name / Ansprechpartner"
                    value={formData.customer_name}
                    onChange={(val) => onUpdate('customer_name', val)}
                    endpoint="/api/management/customers?q="
                    mapResponse={(data) => data.map(c => ({ id: c.id, title: c.name || c.company || 'Unbekannt', subtitle: `${c.company ? c.company + ' • ' : ''}${c.email || ''}`, raw: c }))}
                    onSelect={(c) => {
                        onMultiUpdate({
                            customer_name: c.name || '',
                            customer_company: c.company || '',
                            customer_street: c.street || '',
                            customer_zip: c.zip || '',
                            customer_city: c.city || '',
                            customer_country: c.country || '',
                            customer_email: c.email || '',
                            customer_uid: c.uid || ''
                        });
                    }}
                />
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Firma</span></label>
                    <input type="text" value={formData.customer_company} onChange={e => onUpdate('customer_company', e.target.value)} className="input input-sm input-bordered" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">E-Mail (wird angedruckt)</span></label>
                    <input type="email" value={formData.customer_email} onChange={e => onUpdate('customer_email', e.target.value)} className="input input-sm input-bordered" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">U-ID (Umsatzsteuer-ID)</span></label>
                    <input type="text" value={formData.customer_uid} onChange={e => onUpdate('customer_uid', e.target.value)} className="input input-sm input-bordered" />
                </div>
                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">Straße & Hausnummer</span></label>
                    <input type="text" value={formData.customer_street} onChange={e => onUpdate('customer_street', e.target.value)} className="input input-sm input-bordered" />
                </div>
                <div className="form-control md:col-span-2">
                    <label className="label"><span className="label-text font-bold">PLZ & Stadt</span></label>
                    <div className="flex gap-2">
                        <div className="w-1/3 md:w-32">
                            <AutocompleteInput<LocationResult>
                                value={formData.customer_zip}
                                onChange={val => onUpdate('customer_zip', val)}
                                endpoint="/api/search/locations?type=city&q="
                                mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.postal_code || '', subtitle: loc.name, raw: loc }))}
                                onSelect={(loc) => {
                                    onMultiUpdate({
                                        customer_city: loc.name,
                                        customer_zip: loc.postal_code || formData.customer_zip,
                                        customer_country: loc.country || formData.customer_country
                                    });
                                }}
                                placeholder="PLZ"
                            />
                        </div>
                        <div className="flex-1">
                            <AutocompleteInput<LocationResult>
                                value={formData.customer_city}
                                onChange={val => onUpdate('customer_city', val)}
                                endpoint="/api/search/locations?type=city&q="
                                mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.name, subtitle: loc.postal_code ? loc.postal_code : '', raw: loc }))}
                                onSelect={(loc) => {
                                    onMultiUpdate({
                                        customer_city: loc.name,
                                        customer_zip: loc.postal_code || formData.customer_zip,
                                        customer_country: loc.country || formData.customer_country
                                    });
                                }}
                                placeholder="Stadt"
                            />
                        </div>
                    </div>
                </div>
                <div className="form-control">
                    <AutocompleteInput<LocationResult>
                        label="Land"
                        value={formData.customer_country}
                        onChange={(val) => onUpdate('customer_country', val)}
                        endpoint="/api/search/locations?type=country&q="
                        mapResponse={(data) => data.map(loc => ({ id: loc.id, title: loc.name, subtitle: loc.iso_country || '', raw: loc }))}
                        onSelect={(loc) => onUpdate('customer_country', loc.name)}
                    />
                </div>
            </div>
        </div>
    );
}
