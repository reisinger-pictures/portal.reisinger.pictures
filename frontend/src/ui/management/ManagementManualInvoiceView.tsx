import {useState} from 'react';
import {useAuth} from '../../logic/useAuth';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import RecipientFormSection from './components/RecipientFormSection';
import ManualDocumentHeader from './components/ManualDocumentHeader';
import {InvoiceDiscount, InvoiceItem} from '../../api';
import ShootingCalculatorModal from './components/ShootingCalculatorModal';
import InvoiceItemsTable from './components/invoice/InvoiceItemsTable';
import InvoiceDiscountsSection from './components/invoice/InvoiceDiscountsSection';
import InvoiceTotalSummary from './components/invoice/InvoiceTotalSummary';
import InvoiceDragDropZone from './components/invoice/InvoiceDragDropZone';
import {formatDateToDE, formatLocaleDate} from '../../logic/utils';

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
        return 'Gültig bis ' + formatLocaleDate(d);
    };
    const getInvoiceDefaultDue = () => 'Zahlbar sofort nach Erhalt der Rechnung.';
    const [serviceDateDirty, setServiceDateDirty] = useState(false);

    const [formData, setFormData] = useState<DocumentFormData>(() => ({
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
    }));

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
            <InvoiceDragDropZone
                isDragging={isDragging}
                isOffer={isOffer}
                onFileUpload={handleFileUpload}
            />

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
                        </div>
                    </div>

                    <InvoiceItemsTable
                        items={items}
                        onItemChange={handleItemChange}
                        onAddItem={addItem}
                        onRemoveItem={removeItem}
                        onMoveItemUp={moveItemUp}
                        onMoveItemDown={moveItemDown}
                    />

                    <InvoiceDiscountsSection
                        discounts={discounts}
                        onDiscountChange={handleDiscountChange}
                        onAddDiscount={addDiscount}
                        onRemoveDiscount={removeDiscount}
                    />

                    <InvoiceTotalSummary total={total} />
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
