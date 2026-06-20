import {useState} from 'react';
import {useAuth} from '../../logic/useAuth';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import RecipientFormSection from './components/RecipientFormSection';
import ManualDocumentHeader from './components/ManualDocumentHeader';
import AutocompleteInput from '../components/AutocompleteInput';
import {InvoiceDiscount, InvoiceItem, Product} from '../../api';
import ShootingCalculatorModal from './components/ShootingCalculatorModal';

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

export interface ManagementManualInvoiceViewProps {
    type?: 'invoice' | 'offer';
}

export default function ManagementManualInvoiceView({type = 'invoice'}: ManagementManualInvoiceViewProps) {
    const {user} = useAuth();
    const {showToast} = useUI();
    const docType = type;
    const isOffer = docType === 'offer';

    const [isGenerating, setIsGenerating] = useState(false);
    const [dueDateOption, setDueDateOption] = useState('0');
    const getOfferValidUntil = () => {
        const d = new Date();
        d.setDate(d.getDate() + 14);
        return 'Gültig bis ' + d.toLocaleDateString('de-AT', {day: '2-digit', month: '2-digit', year: 'numeric'});
    };
    const getInvoiceDefaultDue = () => 'Zahlbar sofort nach Erhalt der Rechnung.';
    const [serviceDateDirty, setServiceDateDirty] = useState(false);

    const [formData, setFormData] = useState<DocumentFormData>({
        type: docType,
        invoice_number: (isOffer ? 'A-' : 'R-') + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900),
        date: new Date().toISOString().split('T')[0],
        due_date: isOffer ? getOfferValidUntil() : getInvoiceDefaultDue(),
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

    const [prevDocType, setPrevDocType] = useState(docType);
    if (docType !== prevDocType) {
        setPrevDocType(docType);
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
            due_date: isOffer ? getOfferValidUntil() : getInvoiceDefaultDue()
        }));
    }

    const [items, setItems] = useState<InvoiceItem[]>([{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    const [discounts, setDiscounts] = useState<InvoiceDiscount[]>([]);
    const [isDragging, setIsDragging] = useState(false);

    const [isCalculatorOpen, setIsCalculatorOpen] = useState(false);

    const handleAddPackageFromCalculator = (newItem: InvoiceItem, newDiscount: InvoiceDiscount | null) => {
        setItems(prev => [...prev, newItem]);
        if (newDiscount) {
            setDiscounts(prev => [...prev, newDiscount]);
        }
    };

    if (!user?.is_super_admin) return <div className="p-8"><ErrorMessage message="Keine Berechtigung."/></div>;

    const handleUpdateField = (field: string, value: string) => {
        setFormData(p => {
            const next = {...p, [field]: value};
            if (field === 'date' && !isOffer && !serviceDateDirty) {
                next.service_date = formatDateToDE(value);
            }
            return next;
        });
    };

    const handleOptionChange = (opt: string) => {
        setDueDateOption(opt);
        let text = formData.due_date;
        if (opt === '0') text = isOffer ? getOfferValidUntil() : getInvoiceDefaultDue();
        handleUpdateField('due_date', text);
    };

    const handleServiceDateManualChange = (val: string) => {
        setServiceDateDirty(true);
        handleUpdateField('service_date', val);
    };

    const processPdfFile = async (file: File) => {
        const fd = new FormData();
        fd.append('pdf', file);

        try {
            const res = await fetch('/api/management/invoices/extract-offer', {
                method: 'POST',
                body: fd,
                headers: {'Accept': 'application/json'},
                credentials: 'include'
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || data.message || 'Fehler beim Auslesen.');

            setFormData(prev => ({
                ...prev,
                customer_name: data.customer_name || '',
                customer_company: data.customer_company || '',
                customer_street: data.customer_street || '',
                customer_zip: data.customer_zip || '',
                customer_city: data.customer_city || '',
                customer_country: data.customer_country || '',
                customer_email: data.customer_email || '',
                customer_uid: data.customer_uid || '',
                terms_html: data.terms_html || ''
            }));

            const loadedItems = data.items?.filter((i: InvoiceItem) => i.type === 'item').map((i: InvoiceItem) => ({
                ...i,
                price: i.price / 100
            })) || [];
            const loadedDiscounts = data.items?.filter((i: InvoiceDiscount) => i.type !== 'item').map((i: InvoiceDiscount) => ({
                ...i,
                price: i.price / 100
            })) || [];

            setItems(loadedItems.length > 0 ? loadedItems : [{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
            setDiscounts(loadedDiscounts);

            showToast('success', 'Angebotsdaten erfolgreich übernommen!');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        }
    };

    const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        await processPdfFile(file);
        e.target.value = '';
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        if (!isOffer) setIsDragging(true);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
    };

    const handleDrop = async (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        if (isOffer) return;

        const file = e.dataTransfer.files?.[0];
        if (file && file.type === 'application/pdf') {
            await processPdfFile(file);
        } else if (file) {
            showToast('error', 'Bitte lade eine gültige PDF-Datei hoch.');
        }
    };

    const handleItemChange = (index: number, field: string, value: string | number) => {
        setItems(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    };

    const handleDiscountChange = (index: number, field: string, value: string | number) => {
        setDiscounts(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    };

    const addDiscount = () => setDiscounts([...discounts, {type: 'discount_fixed', description: '', notes: '', price: 0}]);
    const removeDiscount = (index: number) => setDiscounts(discounts.filter((_, i) => i !== index));

    const addItem = () => setItems([...items, {type: 'item', description: '', notes: '', qty: 1, price: 0}]);
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
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                credentials: 'include',
                body: JSON.stringify({
                    ...formData,
                    items: [...items, ...discounts.map(d => ({
                        ...d,
                        qty: 1
                    }))].map((i: InvoiceItem | InvoiceDiscount) => ({
                        ...i, 
                        price: i.type === 'discount_percent' ? Number((i.price * 100).toFixed(4)) : Math.round(i.price * 100)
                    }))
                })
            });
            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                let niceMsg = errData.message || errData.error || 'Fehler beim Generieren (Bitte Eingaben prüfen).';
                if (errData.errors) {
                    const firstErr = Object.values(errData.errors)[0];
                    if (Array.isArray(firstErr)) niceMsg = firstErr[0];
                }
                if (niceMsg.includes('items.') && niceMsg.includes('description')) niceMsg = 'Bitte alle Titel/Namen bei den Leistungen ausfüllen.';
                throw new Error(niceMsg);
            }
            const blob = await res.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = (isOffer ? 'Angebot-' : 'Rechnung-') + formData.invoice_number + '.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            showToast('success', 'Dokument wurde erstellt.');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Fehler');
        }
        setIsGenerating(false);
    };

    const subtotal = items.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const total = discounts.reduce((t, d) => d.type === 'discount_percent' ? t * (1 - d.price / 100) : t - d.price, subtotal);
    const hasInvalidItems = items.some(i => !i.description.trim() || i.qty <= 0) || discounts.some(d => !d.description.trim());
    const isFormValid = items.length > 0 && total >= 0 && !hasInvalidItems;

    return (
        <div
            className={`p-6 md:p-10 max-w-6xl mx-auto w-full relative transition-colors duration-200 ${isDragging ? 'bg-primary/5 rounded-box border-2 border-dashed border-primary' : ''}`}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
        >
            {isDragging && !isOffer && (
                <div
                    className="absolute inset-0 z-50 flex items-center justify-center bg-base-100/80 backdrop-blur-sm rounded-box border-4 border-dashed border-primary m-6 pointer-events-none">
                    <div className="text-center text-primary">
                        <span className="iconify mdi--upload text-6xl mb-2"></span>
                        <h2 className="text-2xl font-bold">Angebot hier ablegen</h2>
                        <p>Die Daten werden automatisch in die Rechnung übernommen.</p>
                    </div>
                </div>
            )}
            <div
                className="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative z-10">
                <div>
                    <h1 className="text-4xl font-bold flex items-center gap-2 mb-2">
                        <span
                            className={`iconify ${isOffer ? 'mdi--file-chart-outline' : 'mdi--file-document-edit-outline'} text-primary`}></span>
                        {isOffer ? 'Manuelles Angebot' : 'Manuelle Rechnung'}
                    </h1>
                    <p className="opacity-70">{isOffer ? 'Erstelle ein unverbindliches Angebot für Kunden.' : 'Erstelle eine freie PDF-Rechnung.'}</p>
                </div>
                {!isOffer && (
                    <div className="flex-none">
                        <label className="btn btn-outline btn-primary shadow-sm cursor-pointer">
                            <span className="iconify mdi--upload text-xl"></span> Angebot importieren (.pdf)
                            <input type="file" accept="application/pdf" className="hidden" onChange={handleFileUpload}/>
                        </label>
                    </div>
                )}
            </div>

            <form onSubmit={handleDownload} className="space-y-8">
                <ManualDocumentHeader
                    docType={docType}
                    data={formData}
                    dueDateOption={dueDateOption}
                    onUpdate={handleUpdateField}
                    onOptionChange={handleOptionChange}
                    onServiceDateChange={handleServiceDateManualChange}
                />

                <RecipientFormSection formData={formData} onUpdate={handleUpdateField}
                                      onMultiUpdate={(u) => setFormData(p => ({...p, ...u}))}/>

                {isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-primary/30 shadow-md">
                        <h2 className="font-bold text-xl mb-4 text-primary">Angebotstext (Einleitung)</h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)}/>
                    </div>
                )}

                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl text-primary">Leistungen / Positionen</h2>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setIsCalculatorOpen(true)}
                                    className="btn btn-sm btn-outline btn-secondary">
                                <span className="iconify mdi--calculator"></span> Paket-Kalkulator
                            </button>
                            <button type="button" onClick={addItem} className="btn btn-sm btn-outline btn-primary">+
                                Leistung hinzufügen
                            </button>
                        </div>
                    </div>

                    <div className="space-y-4">
                        {items.map((item, idx) => (
                            <div key={idx}
                                 className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300">
                                <div className="flex flex-col gap-1 self-center shrink-0 mr-2">
                                    <button type="button" onClick={() => moveItemUp(idx)} disabled={idx === 0}
                                            className="btn btn-xs btn-ghost btn-square"><span
                                        className="iconify mdi--arrow-up text-lg opacity-50"></span></button>
                                    <button type="button" onClick={() => moveItemDown(idx)}
                                            disabled={idx === items.length - 1}
                                            className="btn btn-xs btn-ghost btn-square"><span
                                        className="iconify mdi--arrow-down text-lg opacity-50"></span></button>
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Titel / Name</span></label>
                                    <AutocompleteInput<Product>
                                        value={item.description}
                                        onChange={val => handleItemChange(idx, 'description', val)}
                                        endpoint="/api/management/products?type=item&q="
                                        mapResponse={(data) => data.map(p => ({
                                            id: p.id,
                                            title: p.name,
                                            subtitle: `${p.price.toFixed(2)} €`,
                                            raw: p
                                        }))}
                                        onSelect={(p) => {
                                            handleItemChange(idx, 'description', p.name);
                                            handleItemChange(idx, 'notes', p.description || '');
                                            handleItemChange(idx, 'price', p.price / 100);
                                        }}
                                        placeholder="z.B. Fotoshooting"
                                        className="input input-sm input-bordered w-full"
                                    />
                                </div>
                                <div className="form-control flex-1 w-full">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Zusatz (kleingedruckt)</span></label>
                                    <input type="text" value={item.notes}
                                           onChange={e => handleItemChange(idx, 'notes', e.target.value)}
                                           className="input input-sm input-bordered w-full"
                                           placeholder="Optional"/>
                                </div>
                                <div className="form-control w-20 shrink-0">
                                    <label className="label py-1"><span
                                        className="label-text text-sm font-bold">Menge</span></label>
                                    <input required type="number" step="0.01" min="0.01" value={item.qty}
                                           onChange={e => handleItemChange(idx, 'qty', parseFloat(e.target.value) || 0)}
                                           className="input input-sm input-bordered w-full font-mono text-center"/>
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Preis / Stück</span></label>
                                    <input required type="number" step="any" value={item.price}
                                           onChange={e => handleItemChange(idx, 'price', parseFloat(e.target.value) || 0)}
                                           className="input input-sm input-bordered w-full font-mono text-right"/>
                                </div>
                                <div className="form-control w-full md:w-28 shrink-0">
                                    <label className="label py-1"><span
                                        className="label-text text-sm font-bold">Gesamt</span></label>
                                    <div
                                        className="text-right font-mono font-bold mt-1 text-base-content">{(item.price * item.qty).toFixed(2)} €
                                    </div>
                                </div>
                                <button type="button" onClick={() => removeItem(idx)}
                                        className="btn btn-sm btn-ghost text-error shrink-0 mt-7"><span
                                    className="iconify mdi--trash-can text-lg"></span></button>
                            </div>
                        ))}
                    </div>

                    <div className="mt-6 border-t border-base-300 pt-6">
                        <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                            <h2 className="font-bold text-xl text-primary">Rabatte & Abzüge</h2>
                            <button type="button" onClick={addDiscount} className="btn btn-sm btn-outline btn-primary">+
                                Rabatt hinzufügen
                            </button>
                        </div>

                        <div className="space-y-4">
                            {discounts.map((discount, idx) => (
                                <div key={idx}
                                     className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300">
                                    <div className="form-control w-full md:w-1/4 shrink-0">
                                        <label className="label py-1"><span
                                            className="label-text text-sm font-bold">Art</span></label>
                                        <select value={discount.type}
                                                onChange={e => handleDiscountChange(idx, 'type', e.target.value)}
                                                className="select select-sm select-bordered w-full bg-base-100">
                                            <option value="discount_fixed">Fixer Betrag (€)</option>
                                            <option value="discount_percent">Prozentual (%)</option>
                                        </select>
                                    </div>
                                    <div className="form-control flex-1 w-full">
                                        <label className="label py-1"><span className="label-text text-sm font-bold">Titel / Beschreibung</span></label>
                                        <AutocompleteInput<Product>
                                            value={discount.description}
                                            onChange={val => handleDiscountChange(idx, 'description', val)}
                                            endpoint="/api/management/products?type=discount_fixed,discount_percent&q="
                                            mapResponse={(data) => data.map(p => ({
                                                id: p.id,
                                                title: p.name,
                                                subtitle: `${p.price.toFixed(2)} ${p.type === 'discount_percent' ? '%' : '€'}`,
                                                raw: p
                                            }))}
                                            onSelect={(p) => {
                                                handleDiscountChange(idx, 'type', p.type || 'discount_fixed');
                                                handleDiscountChange(idx, 'description', p.name);
                                                handleDiscountChange(idx, 'notes', p.description || '');
                                                handleDiscountChange(idx, 'price', p.type === 'discount_percent' ? p.price : p.price / 100);
                                            }}
                                            placeholder="z.B. Stammkundenrabatt"
                                            className="input input-sm input-bordered w-full bg-base-100"
                                        />
                                    </div>
                                    <div className="form-control w-full md:w-32 shrink-0">
                                        <label className="label py-1"><span
                                            className="label-text text-sm font-bold">Wert</span></label>
                                        <div className="join w-full">
                                            <input required type="number" step="any" min="0" value={discount.price}
                                                   onChange={e => handleDiscountChange(idx, 'price', parseFloat(e.target.value) || 0)}
                                                   className="input input-sm input-bordered join-item w-full font-mono text-right bg-base-100"/>
                                            <span
                                                className="btn btn-sm btn-disabled join-item">{discount.type === 'discount_percent' ? '%' : '€'}</span>
                                        </div>
                                    </div>
                                    <button type="button" onClick={() => removeDiscount(idx)}
                                            className="btn btn-sm btn-ghost text-error shrink-0 mt-7"><span
                                        className="iconify mdi--trash-can text-lg"></span></button>
                                </div>
                            ))}
                            {discounts.length === 0 &&
                                <p className="text-sm opacity-50 italic px-2">Keine Rabatte angewendet.</p>}
                        </div>
                    </div>

                    <div
                        className="text-right text-2xl font-bold mt-6 pt-4 border-t border-base-300">Gesamtbetrag: {total.toFixed(2)} €
                    </div>
                </div>

                {!isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4">Zusatztexte / Sonderkonditionen</h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)}/>
                    </div>
                )}

                <div className="flex justify-end pt-4 pb-20">
                    <button type="submit" disabled={isGenerating || !isFormValid}
                            className="btn btn-primary btn-lg shadow-xl w-full md:w-auto"
                            title={!isFormValid ? "Bitte alle Pflichtfelder ausfüllen (Titel/Menge)." : ""}>
                        {isGenerating ? <span className="loading loading-spinner"></span> : 'PDF Generieren'}
                    </button>
                </div>
            </form>

            <ShootingCalculatorModal
                isOpen={isCalculatorOpen}
                onClose={() => setIsCalculatorOpen(false)}
                onAddPackage={handleAddPackageFromCalculator}
            />
        </div>
    );
}
