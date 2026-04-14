import { useState, useEffect } from 'react';
import { useAuth } from '../../logic/useAuth';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import RecipientFormSection from './components/RecipientFormSection';
import ManualDocumentHeader from './components/ManualDocumentHeader';

export interface DocumentFormData {
    type: string;
    invoice_number: string;
    date: string;
    due_date: string;
    service_date: string;
    validity: string;
    customer_name: string;
    customer_company: string;
    customer_street: string;
    customer_zip: string;
    customer_city: string;
    customer_country: string;
    customer_email: string;
    customer_uid: string;
    terms_html: string;
    [key: string]: string;
}

export default function ManagementManualInvoiceView({ type = 'invoice' }: { type?: 'invoice' | 'offer' }) {
    const { user } = useAuth();
    const { showToast } = useUI();
    const docType = type;
    const isOffer = docType === 'offer';

    const [isGenerating, setIsGenerating] = useState(false);
    const [dueDateOption, setDueDateOption] = useState('0');
    const [serviceDateDirty, setServiceDateDirty] = useState(false);

    const [formData, setFormData] = useState<DocumentFormData>({
        type: docType,
        invoice_number: (isOffer ? 'A-' : 'R-') + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900),
        date: new Date().toISOString().split('T')[0],
        due_date: '', // Wird via useEffect initialisiert
        service_date: '',
        validity: '',
        customer_name: '',
        customer_company: '',
        customer_street: '',
        customer_zip: '',
        customer_city: '',
        customer_country: '',
        customer_email: '',
        customer_uid: '',
        terms_html: ''
    });

    const formatDateToDE = (iso: string) => {
        if (!iso) return '';
        const parts = iso.split('-');
        if (parts.length === 3) return `${parts[2]}.${parts[1]}.${parts[0]}`;
        return iso;
    };

    // Reset Form when URL type changes
    useEffect(() => {
        setServiceDateDirty(false);
        setDueDateOption('0');
        const newDate = new Date().toISOString().split('T')[0];
        setFormData(prev => ({
            ...prev,
            type: docType,
            invoice_number: (isOffer ? 'A-' : 'R-') + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900),
            date: newDate,
            service_date: isOffer ? '' : formatDateToDE(newDate),
            validity: isOffer ? '14 Tage ab Ausstellungsdatum' : '',
        }));
    }, [docType, isOffer]);

    // INTELLIGENTE SYNC LOGIK: Rechnungsdatum -> Leistungsdatum
    useEffect(() => {
        if (!isOffer && !serviceDateDirty) {
            setFormData(prev => ({ ...prev, service_date: formatDateToDE(formData.date) }));
        }
    }, [formData.date, isOffer, serviceDateDirty]);

    // FÄLLIGKEITS-LOGIK WIEDERHERGESTELLT
    useEffect(() => {
        if (dueDateOption === '0') {
            handleUpdateField('due_date', isOffer ? 'Wir freuen uns auf Ihre Rückmeldung.' : 'Zahlbar sofort nach Erhalt der Rechnung.');
        } else if (dueDateOption === '14') {
            handleUpdateField('due_date', isOffer ? 'Dieses Angebot ist gültig für 14 Tage ab Ausstellungsdatum.' : 'Zahlbar innerhalb von 14 Tagen nach Rechnungsdatum.');
        } else if (dueDateOption === '1m') {
            handleUpdateField('due_date', isOffer ? 'Dieses Angebot ist gültig für 1 Monat ab Ausstellungsdatum.' : 'Zahlbar innerhalb von 1 Monat nach Rechnungsdatum.');
        }
    }, [dueDateOption, isOffer]);

    const [items, setItems] = useState([{ type: 'item', description: '', notes: '', qty: 1, price: 0 }]);
    const [discounts] = useState<{type: string, price: number}[]>([]);

    if (!user?.is_super_admin) return <div className="p-8"><ErrorMessage message="Keine Berechtigung." /></div>;

    const handleUpdateField = (field: string, value: string) => setFormData(p => ({ ...p, [field]: value }));

    const handleServiceDateManualChange = (val: string) => {
        setServiceDateDirty(true);
        handleUpdateField('service_date', val);
    };

    const handleItemChange = (index: number, field: string, value: string | number) => {
        const newItems = [...items];
        newItems[index] = { ...newItems[index], [field]: value };
        setItems(newItems);
    };

    const addItem = () => setItems([...items, { type: 'item', description: '', notes: '', qty: 1, price: 0 }]);
    const removeItem = (index: number) => setItems(items.filter((_, i) => i !== index));
    const moveItemUp = (index: number) => {
        if (index === 0) return;
        const newItems = [...items];
        const temp = newItems[index - 1];
        newItems[index - 1] = newItems[index];
        newItems[index] = temp;
        setItems(newItems);
    };
    const moveItemDown = (index: number) => {
        if (index === items.length - 1) return;
        const newItems = [...items];
        const temp = newItems[index + 1];
        newItems[index + 1] = newItems[index];
        newItems[index] = temp;
        setItems(newItems);
    };

    const handleDownload = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsGenerating(true);
        try {
            const res = await fetch('/api/management/invoices/manual', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...formData, items: [...items, ...discounts] })
            });
            if (!res.ok) throw new Error('Fehler beim Generieren.');
            const blob = await res.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = (isOffer ? 'Angebot-' : 'Rechnung-') + formData.invoice_number + '.pdf';
            a.click();
            showToast('success', 'Dokument wurde erstellt.');
        } catch (err: unknown) { showToast('error', err instanceof Error ? err.message : 'Fehler'); }
        setIsGenerating(false);
    };

    const subtotal = items.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const total = discounts.reduce((t, d) => d.type === 'discount_percent' ? t * (1 - d.price/100) : t - d.price, subtotal);

    return (
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full">
            <div className="mb-8">
                <h1 className="text-4xl font-bold flex items-center gap-2 mb-2">
                    <span className={`iconify ${isOffer ? 'mdi--file-chart-outline' : 'mdi--file-document-edit-outline'} text-primary`}></span>
                    {isOffer ? 'Manuelles Angebot' : 'Manuelle Rechnung'}
                </h1>
                <p className="opacity-70">{isOffer ? 'Erstelle ein unverbindliches Angebot für Kunden.' : 'Erstelle eine freie PDF-Rechnung.'}</p>
            </div>

            <form onSubmit={handleDownload} className="space-y-8">
                <ManualDocumentHeader 
                    docType={docType} 
                    data={formData} 
                    dueDateOption={dueDateOption}
                    onUpdate={handleUpdateField} 
                    onOptionChange={setDueDateOption}
                    onServiceDateChange={handleServiceDateManualChange}
                />
                
                <RecipientFormSection formData={formData} onUpdate={handleUpdateField} onMultiUpdate={(u) => setFormData(p => ({...p, ...u}))} />
                
                {isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-primary/30 shadow-md">
                        <h2 className="font-bold text-xl mb-4 text-primary">Angebotstext (Einleitung)</h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)} />
                    </div>
                )}

                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl text-secondary">Leistungen / Positionen</h2>
                        <button type="button" onClick={addItem} className="btn btn-sm btn-outline btn-secondary">+ Leistung hinzufügen</button>
                    </div>
                    
                    <div className="space-y-4">
                        {items.map((item, idx) => (
                            <div key={idx} className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300">
                                <div className="flex flex-col gap-1 self-center shrink-0 mr-2">
                                    <button type="button" onClick={() => moveItemUp(idx)} disabled={idx === 0} className="btn btn-xs btn-ghost btn-square"><span className="iconify mdi--arrow-up text-lg opacity-50"></span></button>
                                    <button type="button" onClick={() => moveItemDown(idx)} disabled={idx === items.length - 1} className="btn btn-xs btn-ghost btn-square"><span className="iconify mdi--arrow-down text-lg opacity-50"></span></button>
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Titel / Name</span></label>
                                    <input required type="text" value={item.description} onChange={e => handleItemChange(idx, 'description', e.target.value)} className="input input-sm input-bordered w-full" placeholder="z.B. Fotoshooting" />
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Zusatz (kleingedruckt)</span></label>
                                    <input type="text" value={item.notes} onChange={e => handleItemChange(idx, 'notes', e.target.value)} className="input input-sm input-bordered w-full" placeholder="Optional" />
                                </div>
                                <div className="form-control w-20 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Menge</span></label>
                                    <input required type="number" step="0.01" min="0.01" value={item.qty} onChange={e => handleItemChange(idx, 'qty', parseFloat(e.target.value) || 0)} className="input input-sm input-bordered w-full font-mono text-center" />
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Preis / Stück</span></label>
                                    <input required type="number" step="any" value={item.price} onChange={e => handleItemChange(idx, 'price', parseFloat(e.target.value) || 0)} className="input input-sm input-bordered w-full font-mono text-right" />
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Gesamt</span></label>
                                    <div className="text-right font-mono font-bold mt-1 text-base-content">{(item.price * item.qty).toFixed(2)} €</div>
                                </div>
                                <button type="button" onClick={() => removeItem(idx)} className="btn btn-sm btn-ghost text-error shrink-0 mt-7"><span className="iconify mdi--trash-can text-lg"></span></button>
                            </div>
                        ))}
                    </div>
                    <div className="text-right text-2xl font-bold mt-6 pt-4 border-t border-base-300">Gesamtbetrag: {total.toFixed(2)} €</div>
                </div>

                {!isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4">Zusatztexte / Sonderkonditionen</h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)} />
                    </div>
                )}

                <div className="flex justify-end pt-4 pb-20">
                    <button type="submit" disabled={isGenerating} className="btn btn-primary btn-lg shadow-xl w-full md:w-auto">
                        {isGenerating ? <span className="loading loading-spinner"></span> : 'PDF Generieren'}
                    </button>
                </div>
            </form>
        </div>
    );
}
