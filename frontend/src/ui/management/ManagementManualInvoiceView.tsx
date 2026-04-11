import { useState, useEffect } from 'react';
import { useAuth } from '../../logic/useAuth';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import { useLicenseTerms } from '../../logic/useLicenseTerms';

export default function ManagementManualInvoiceView() {
    const { user } = useAuth();
    const { terms, isLoading: termsLoading } = useLicenseTerms();
    const { showToast } = useUI();
    const [isGenerating, setIsGenerating] = useState(false);
    const [dueDateOption, setDueDateOption] = useState('0');

    const isMissingInfo = !termsLoading && (!terms?.bank_holder || !terms?.company_street || !terms?.company_zip || !terms?.company_city || !terms?.bank_iban);

    const [formData, setFormData] = useState({
        invoice_number: 'RE-' + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900),
        date: new Date().toISOString().split('T')[0],
        due_date: 'Zahlbar sofort nach Erhalt der Rechnung.',
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

    const [items, setItems] = useState([
        { type: 'item', description: '', notes: '', qty: 1, price: 0 }
    ]);
    
    const [discounts, setDiscounts] = useState<any[]>([]);

    // Freitext-Logik für Fälligkeit
    useEffect(() => {
        if (dueDateOption === '0') {
            setFormData(prev => ({ ...prev, due_date: 'Zahlbar sofort nach Erhalt der Rechnung.' }));
        } else if (dueDateOption === '14') {
            setFormData(prev => ({ ...prev, due_date: 'Zahlbar innerhalb von 14 Tagen nach Rechnungsdatum.' }));
        } else if (dueDateOption === '1m') {
            setFormData(prev => ({ ...prev, due_date: 'Zahlbar innerhalb von 1 Monat nach Rechnungsdatum.' }));
        } else if (dueDateOption === 'custom') {
            if (formData.due_date.startsWith('Zahlbar')) {
                setFormData(prev => ({ ...prev, due_date: '' }));
            }
        }
    }, [dueDateOption]);

    if (!user?.is_super_admin) {
        return <div className="p-8"><ErrorMessage message="Keine Berechtigung. Diese Funktion ist nur für Super Admins verfügbar." /></div>;
    }

    const handleChange = (field: string, value: string | number) => {
        setFormData(prev => ({ ...prev, [field]: value }));
    };

    // ITEM LOGIC
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

    // DISCOUNT LOGIC
    const handleDiscountChange = (index: number, field: string, value: string | number) => {
        const newDiscounts = [...discounts];
        newDiscounts[index] = { ...newDiscounts[index], [field]: value };
        setDiscounts(newDiscounts);
    };
    const addDiscount = () => setDiscounts([...discounts, { type: 'discount_fixed', description: 'Rabatt', notes: '', price: 0 }]);
    const removeDiscount = (index: number) => setDiscounts(discounts.filter((_, i) => i !== index));
    const moveDiscountUp = (index: number) => {
        if (index === 0) return;
        const newArr = [...discounts];
        const temp = newArr[index - 1];
        newArr[index - 1] = newArr[index];
        newArr[index] = temp;
        setDiscounts(newArr);
    };
    const moveDiscountDown = (index: number) => {
        if (index === discounts.length - 1) return;
        const newArr = [...discounts];
        const temp = newArr[index + 1];
        newArr[index + 1] = newArr[index];
        newArr[index] = temp;
        setDiscounts(newArr);
    };

    const handleDownload = async (e: React.FormEvent) => {
        e.preventDefault();
        if (items.length === 0) {
            showToast('error', 'Bitte füge mindestens eine Leistung hinzu.');
            return;
        }

        setIsGenerating(true);
        try {
            const payloadItems = [...items.map(i => ({ ...i, type: 'item' })), ...discounts];

            const res = await fetch('/api/management/invoices/manual', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/pdf' },
                body: JSON.stringify({ ...formData, items: payloadItems })
            });
            
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.error || 'Fehler beim Generieren der Rechnung.');
            }

            const blob = await res.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = formData.invoice_number + '.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showToast('success', 'Rechnung erfolgreich generiert!');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Unbekannter Fehler');
        }
        setIsGenerating(false);
    };

    let subtotal = 0;
    items.forEach(i => subtotal += (Number(i.price) || 0) * (Number(i.qty) || 1));
    
    let runningTotal = subtotal;
    const calculatedDiscounts = discounts.map(d => {
        let amt = 0;
        if (d.type === 'discount_fixed') amt = Number(d.price) || 0;
        else if (d.type === 'discount_percent') amt = runningTotal * ((Number(d.price) || 0) / 100);
        runningTotal -= amt;
        return { ...d, rowTotal: -amt };
    });
    
    const total = Math.max(0, runningTotal);

    return (
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full">
            <div className="mb-8">
                <h1 className="text-4xl font-bold flex items-center gap-2 mb-2">
                    <span className="iconify mdi--file-document-edit-outline text-primary"></span> Manuelle Rechnung
                </h1>
                <p className="opacity-70">Erstelle freie PDFs für Sondervereinbarungen.</p>
            </div>

            {isMissingInfo && (
                <div className="alert alert-error shadow-sm mb-8">
                    <span className="iconify mdi--alert-circle text-xl"></span>
                    <div>
                        <h3 className="font-bold">Fehlende Firmendaten!</h3>
                        <p className="text-sm">Du musst zuerst deine vollständigen Firmen- und Bankdaten in den <a href="/settings" className="underline font-bold">Einstellungen</a> hinterlegen, bevor du Rechnungen generieren kannst.</p>
                    </div>
                </div>
            )}

            <form onSubmit={handleDownload} className="space-y-8">
                {/* 1. Kopfdaten */}
                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4">Rechnungsdetails</h2>
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Rechnungsnummer</span></label>
                            <input required type="text" value={formData.invoice_number} onChange={e => handleChange('invoice_number', e.target.value)} className="input input-sm input-bordered font-mono" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Datum</span></label>
                            <input required type="date" value={formData.date} onChange={e => handleChange('date', e.target.value)} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control lg:col-span-2">
                            <label className="label"><span className="label-text font-bold">Fälligkeit (Text im PDF)</span></label>
                            <div className="flex flex-col sm:flex-row gap-2 w-full">
                                <select className="select select-sm select-bordered w-full sm:w-1/3" value={dueDateOption} onChange={e => setDueDateOption(e.target.value)}>
                                    <option value="0">Sofort</option>
                                    <option value="14">14 Tage</option>
                                    <option value="1m">1 Monat</option>
                                    <option value="custom">Freitext...</option>
                                </select>
                                {dueDateOption !== 'custom' ? (
                                    <div className="input input-sm input-bordered flex-1 bg-base-200 opacity-70 text-xs flex items-center overflow-hidden whitespace-nowrap" title={formData.due_date}>{formData.due_date}</div>
                                ) : (
                                    <input type="text" required value={formData.due_date} onChange={e => handleChange('due_date', e.target.value)} className="input input-sm input-bordered flex-1" placeholder="z.B. Zahlbar bis zum 25.04.2026" />
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. Empfänger */}
                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4">Rechnungsempfänger</h2>
                    <p className="text-sm opacity-60 mb-4">Lasse diese Felder leer, um den Block im PDF vollständig auszublenden.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Name / Ansprechpartner</span></label>
                            <input type="text" value={formData.customer_name} onChange={e => handleChange('customer_name', e.target.value)} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Firma</span></label>
                            <input type="text" value={formData.customer_company} onChange={e => handleChange('customer_company', e.target.value)} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail (wird angedruckt)</span></label>
                            <input type="email" value={formData.customer_email} onChange={e => handleChange('customer_email', e.target.value)} className="input input-sm input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">U-ID (Umsatzsteuer-ID)</span></label>
                            <input type="text" value={formData.customer_uid} onChange={e => handleChange('customer_uid', e.target.value)} className="input input-sm input-bordered" />
                        </div>

                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Adresse</span></label>
                            <div className="grid  md:grid-cols-5 gap-2">
                                <input type="text" placeholder="Adreesse" value={formData.customer_street} onChange={e => handleChange('customer_street', e.target.value)} className="input input-bordered md:col-span-2" />
                                <input type="text" placeholder="PLZ" value={formData.customer_zip} onChange={e => handleChange('customer_zip', e.target.value)} className="input input-bordered" />
                                <input type="text" placeholder="Stadt" value={formData.customer_city} onChange={e => handleChange('customer_city', e.target.value)} className="input input-bordered " />
                                <input type="text" value={formData.customer_country} onChange={e => handleChange('customer_country', e.target.value)} className="input input-bordered" placeholder="z.B. Österreich" />
                            </div>
                        </div>

                    </div>
                </div>

                {/* 3. Leistungen */}
                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl text-secondary">Leistungen</h2>
                        <button type="button" onClick={addItem} className="btn btn-sm btn-outline btn-secondary">+ Leistung hinzufügen</button>
                    </div>
                    
                    <div className="space-y-4">
                        {items.map((item, idx) => {
                            const rowTotal = (Number(item.price) || 0) * (Number(item.qty) || 1);
                            return (
                            <div key={idx} className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300">
                                <div className="flex flex-col gap-1 self-center shrink-0 mr-2">
                                    <button type="button" onClick={() => moveItemUp(idx)} disabled={idx === 0} className="btn btn-xs btn-ghost btn-square" title="Nach oben"><span className="iconify mdi--arrow-up text-lg opacity-50"></span></button>
                                    <button type="button" onClick={() => moveItemDown(idx)} disabled={idx === items.length - 1} className="btn btn-xs btn-ghost btn-square" title="Nach unten"><span className="iconify mdi--arrow-down text-lg opacity-50"></span></button>
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Titel / Name *</span></label>
                                    <input required type="text" value={item.description} onChange={e => handleItemChange(idx, 'description', e.target.value)} className="input input-sm input-bordered w-full" placeholder="z.B. Fotoshooting" />
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Zusatz (kleingedruckt)</span></label>
                                    <input type="text" value={item.notes} onChange={e => handleItemChange(idx, 'notes', e.target.value)} className="input input-sm input-bordered w-full" placeholder="Optional" />
                                </div>
                                <div className="form-control w-20 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Stück *</span></label>
                                    <input required type="number" step="0.01" min="0.01" value={item.qty} onChange={e => handleItemChange(idx, 'qty', parseFloat(e.target.value) || 0)} className="input input-sm input-bordered w-full font-mono text-center" />
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Preis / Stück *</span></label>
                                    <input required type="number" step="0.01" value={item.price} onChange={e => handleItemChange(idx, 'price', parseFloat(e.target.value) || 0)} className="input input-sm input-bordered w-full font-mono text-right" />
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span className="label-text text-xs font-bold">Gesamt</span></label>
                                    <div className="text-right font-mono font-bold mt-1 text-base-content">{rowTotal.toFixed(2)} €</div>
                                </div>
                                <button type="button" onClick={() => removeItem(idx)} className="btn btn-sm btn-ghost text-error shrink-0 mt-7" title="Entfernen"><span className="iconify mdi--trash-can text-lg"></span></button>
                            </div>
                        )})}
                    </div>
                </div>

                {/* 4. Rabatte */}
                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl text-primary">Rabatte & Abzüge</h2>
                        <button type="button" onClick={addDiscount} className="btn btn-sm btn-outline btn-primary">+ Rabatt hinzufügen</button>
                    </div>
                    
                    {discounts.length > 0 && (
                        <div className="space-y-4 mb-6">
                            {calculatedDiscounts.map((discount, idx) => (
                                <div key={idx} className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-primary/30 shadow-inner">
                                    <div className="flex flex-col gap-1 self-center shrink-0 mr-2">
                                        <button type="button" onClick={() => moveDiscountUp(idx)} disabled={idx === 0} className="btn btn-xs btn-ghost btn-square" title="Nach oben"><span className="iconify mdi--arrow-up text-lg opacity-50"></span></button>
                                        <button type="button" onClick={() => moveDiscountDown(idx)} disabled={idx === discounts.length - 1} className="btn btn-xs btn-ghost btn-square" title="Nach unten"><span className="iconify mdi--arrow-down text-lg opacity-50"></span></button>
                                    </div>
                                    <div className="form-control w-full md:w-32 shrink-0">
                                        <label className="label py-1"><span className="label-text text-xs font-bold text-primary">Typ</span></label>
                                        <select value={discount.type} onChange={e => handleDiscountChange(idx, 'type', e.target.value)} className="select select-sm select-bordered w-full text-xs text-primary font-bold">
                                            <option value="discount_fixed">Rabatt (€)</option>
                                            <option value="discount_percent">Rabatt (%)</option>
                                        </select>
                                    </div>
                                    <div className="form-control flex-1 w-full">
                                        <label className="label py-1"><span className="label-text text-xs font-bold text-primary">Titel / Grund *</span></label>
                                        <input required type="text" value={discount.description} onChange={e => handleDiscountChange(idx, 'description', e.target.value)} className="input input-sm input-bordered w-full" placeholder="z.B. Neukundenrabatt" />
                                    </div>
                                    <div className="form-control flex-1 w-full">
                                        <label className="label py-1"><span className="label-text text-xs font-bold text-primary">Zusatz (kleingedruckt)</span></label>
                                        <input type="text" value={discount.notes} onChange={e => handleDiscountChange(idx, 'notes', e.target.value)} className="input input-sm input-bordered w-full" placeholder="Optional" />
                                    </div>
                                    <div className="form-control w-full md:w-28 shrink-0">
                                        <label className="label py-1"><span className="label-text text-xs font-bold text-primary">{discount.type === 'discount_percent' ? 'Wert (%) *' : 'Wert (€) *'}</span></label>
                                        <input required type="number" step="0.01" min="0" value={discount.price} onChange={e => handleDiscountChange(idx, 'price', parseFloat(e.target.value) || 0)} className="input input-sm input-bordered w-full font-mono text-right" />
                                    </div>
                                    <div className="form-control w-full md:w-28 shrink-0">
                                        <label className="label py-1"><span className="label-text text-xs font-bold text-primary">Abzug</span></label>
                                        <div className="text-right font-mono font-bold mt-1 text-primary">{discount.rowTotal.toFixed(2)} €</div>
                                    </div>
                                    <button type="button" onClick={() => removeDiscount(idx)} className="btn btn-sm btn-ghost text-error shrink-0 mt-7" title="Entfernen"><span className="iconify mdi--trash-can text-lg"></span></button>
                                </div>
                            ))}
                        </div>
                    )}
                    
                    <div className="flex flex-col items-end gap-2 text-base pt-6 border-t border-base-300">
                        {discounts.length > 0 && (
                            <div className="flex justify-between w-64 items-center mb-4">
                                <span className="opacity-70">Zwischensumme:</span>
                                <span className="font-mono text-base-content">{subtotal.toFixed(2)} €</span>
                            </div>
                        )}
                        <div className="flex justify-between w-64 items-center text-xl mt-2">
                            <span className="font-bold">Rechnungsbetrag:</span>
                            <span className="font-mono text-primary font-bold">{total.toFixed(2)} €</span>
                        </div>
                    </div>
                </div>

                {/* 5. Sonderkonditionen (Tiptap Editor) */}
                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl">Sonderkonditionen</h2>
                    </div>
                    <div className="form-control">
                        <span className="label-text-alt opacity-70 mb-2">Dieser Text wird am Ende der Rechnung unter den Positionen gedruckt. Nutze den Editor für Listen oder Überschriften.</span>
                        <WysiwygEditor value={formData.terms_html} onChange={val => handleChange('terms_html', val)} />
                    </div>
                </div>

                {/* Submit */}
                <div className="flex justify-end pt-4 pb-12">
                    <button type="submit" disabled={isGenerating || items.length === 0 || isMissingInfo} className="btn btn-primary btn-lg w-full md:w-auto shadow-lg">
                        {isGenerating ? <span className="loading loading-spinner"></span> : <><span className="iconify mdi--file-pdf-box text-xl"></span> PDF Generieren & Herunterladen</>}
                    </button>
                </div>
            </form>
        </div>
    );
}