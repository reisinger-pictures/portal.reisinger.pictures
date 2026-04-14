import React from 'react';

interface Props {
    docType: 'invoice' | 'offer';
    data: any;
    dueDateOption: string;
    onUpdate: (field: string, value: string) => void;
    onOptionChange: (opt: string) => void;
    onServiceDateChange: (val: string) => void;
}

export default function ManualDocumentHeader({ docType, data, dueDateOption, onUpdate, onOptionChange, onServiceDateChange }: Props) {
    const isOffer = docType === 'offer';

    return (
        <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
            <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4">
                {isOffer ? 'Angebotsdetails' : 'Rechnungsdetails'}
            </h2>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Belegnummer</span></label>
                    <input required type="text" value={data.invoice_number} onChange={e => onUpdate('invoice_number', e.target.value)} className="input input-sm input-bordered font-mono" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">{isOffer ? 'Angebotsdatum' : 'Rechnungsdatum'}</span></label>
                    <input required type="date" value={data.date} onChange={e => onUpdate('date', e.target.value)} className="input input-sm input-bordered" />
                </div>
                
                {isOffer ? (
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Gültigkeit (Kurzform)</span></label>
                        <input type="text" value={data.validity || ''} onChange={e => onUpdate('validity', e.target.value)} className="input input-sm input-bordered" placeholder="z.B. 14 Tage" />
                    </div>
                ) : (
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Leistungsdatum / Zeitraum</span></label>
                        <input 
                            type="text" 
                            value={data.service_date || ''} 
                            onChange={e => onServiceDateChange(e.target.value)} 
                            className="input input-sm input-bordered" 
                            placeholder="Wird automatisch befüllt..." 
                        />
                    </div>
                )}

                <div className="form-control lg:col-span-3">
                    <label className="label"><span className="label-text font-bold">{isOffer ? 'Hinweistext (Gültigkeit & Annahme)' : 'Zahlungsziel / Fälligkeitstext'}</span></label>
                    <div className="flex flex-col sm:flex-row gap-2 w-full">
                        <select className="select select-sm select-bordered w-full sm:w-1/3" value={dueDateOption} onChange={e => onOptionChange(e.target.value)}>
                            <option value="0">Sofort</option>
                            <option value="14">14 Tage</option>
                            <option value="1m">1 Monat</option>
                            <option value="custom">Freitext...</option>
                        </select>
                        {dueDateOption !== 'custom' ? (
                            <div className="input input-sm input-bordered flex-1 bg-base-200 opacity-70 text-xs flex items-center overflow-hidden whitespace-nowrap" title={data.due_date}>{data.due_date}</div>
                        ) : (
                            <input type="text" required value={data.due_date} onChange={e => onUpdate('due_date', e.target.value)} className="input input-sm input-bordered flex-1" placeholder="Individueller Text..." />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
