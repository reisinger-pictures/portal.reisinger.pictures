import {useState} from 'react';
import {t} from "@lingui/core/macro";
import {useUI} from '../ui/components/UIContext';
import {DocumentFormData, InvoiceDiscount, InvoiceItem} from '../api';
import {formatDateToDE, formatLocaleDate, moveArrayItemUp, moveArrayItemDown} from './utils';

/**
 * Check if an InvoiceItem is an empty placeholder row (type=item, description+notes blank,
 * default qty=1, price=0). Used to consistently filter out leftover empty rows across
 * multiple data-loading paths (K-01 / K-02).
 */
export function isEmptyRow(i: InvoiceItem): boolean {
    if (i.type === 'item') {
        return !i.description.trim() && !i.notes.trim() && i.qty === 1 && i.price === 0;
    }
    if (i.type === 'discount_fixed' || i.type === 'discount_percent') {
        return !i.description.trim() && !i.notes.trim() && i.price === 0;
    }
    return false;
}

export function useInvoiceDraft(type: 'invoice' | 'offer' = 'invoice') {
    const {showToast, setUnsavedChanges} = useUI();
    const isOffer = type === 'offer';

    const getOfferValidUntil = () => {
        const d = new Date();
        d.setDate(d.getDate() + 14);
        const validUntil = formatLocaleDate(d);
        return t`Gültig bis ${validUntil}`;
    };
    const getInvoiceDefaultDue = () => t`Zahlbar sofort nach Erhalt der Rechnung.`;

    const [isGenerating, setIsGenerating] = useState(false);
    const [dueDateOption, setDueDateOption] = useState('0');
    const [serviceDateDirty, setServiceDateDirty] = useState(false);

    const [formData, setFormData] = useState<DocumentFormData>(() => ({
        type,
        invoice_number: (isOffer ? 'A-' : 'R-') + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900),
        date: new Date().toISOString().split('T')[0],
        due_date: isOffer ? getOfferValidUntil() : getInvoiceDefaultDue(),
        service_date: isOffer ? '' : formatDateToDE(new Date().toISOString().split('T')[0]),
        validity: '',
        customer_name: '',
        customer_company: '',
        customer_street: '',
        customer_zip: '',
        customer_city: '',
        customer_country: '',
        customer_email: '',
        customer_uid: '',
        terms_html: '',
    }));

    const [items, setItems] = useState<InvoiceItem[]>([{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    const [discounts, setDiscounts] = useState<InvoiceDiscount[]>([]);

    const [isDirty, setIsDirty] = useState(false);
    const markDirty = () => {
        if (!isDirty) {
            setIsDirty(true);
            setUnsavedChanges(true);
        }
    };

    const handleUpdateField = (field: string, value: string) => {
        markDirty();
        setFormData(p => {
            const next = {...p, [field]: value};
            if (field === 'date' && !isOffer && !serviceDateDirty) {
                next.service_date = formatDateToDE(value);
            }
            return next;
        });
    };

    const handleOptionChange = (opt: string) => {
        markDirty();
        setDueDateOption(opt);
        if (opt === '0') {
            handleUpdateField('due_date', isOffer ? getOfferValidUntil() : getInvoiceDefaultDue());
        }
    };

    const handleServiceDateManualChange = (val: string) => {
        markDirty();
        setServiceDateDirty(true);
        handleUpdateField('service_date', val);
    };

    const handleItemChange = (index: number, field: string, value: string | number) => {
        markDirty();
        setItems(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    };

    const handleDiscountChange = (index: number, field: string, value: string | number) => {
        markDirty();
        setDiscounts(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    };

    const addItem = () => {
        markDirty();
        setItems(prev => [...prev, {type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    };

    const removeItem = (index: number) => {
        markDirty();
        setItems(prev => prev.filter((_, i) => i !== index));
    };

    const moveItemUp = (index: number) => {
        if (index === 0) return;
        markDirty();
        setItems(prev => moveArrayItemUp(prev, index));
    };

    const moveItemDown = (index: number) => {
        setItems(prev => {
            if (index === prev.length - 1) return prev;
            const result = moveArrayItemDown(prev, index);
            markDirty();
            return result;
        });
    };

    const addDiscount = () => {
        markDirty();
        setDiscounts(prev => [...prev, {type: 'discount_fixed', description: '', notes: '', price: 0}]);
    };

    const removeDiscount = (index: number) => {
        markDirty();
        setDiscounts(prev => prev.filter((_, i) => i !== index));
    };

    const moveDiscountUp = (index: number) => {
        if (index === 0) return;
        markDirty();
        setDiscounts(prev => moveArrayItemUp(prev, index));
    };

    const moveDiscountDown = (index: number) => {
        setDiscounts(prev => moveArrayItemDown(prev, index));
        markDirty();
    };

    const handleAddPackageFromCalculator = (newItem: InvoiceItem, newDiscount: InvoiceDiscount | null) => {
        markDirty();
        setItems(prev => [newItem, ...prev.filter(i => !isEmptyRow(i))]);
        if (newDiscount) {
            setDiscounts(prev => [...prev, newDiscount]);
        }
    };

    const handleMultiUpdate = (updates: Record<string, string>) => {
        markDirty();
        setFormData(prev => ({...prev, ...updates}));
    };

    const loadExtractedData = (data: {
        customer_name?: string;
        customer_company?: string;
        customer_street?: string;
        customer_zip?: string;
        customer_city?: string;
        customer_country?: string;
        customer_email?: string;
        customer_uid?: string;
        terms_html?: string;
        items: InvoiceItem[];
        discounts: InvoiceDiscount[];
    }) => {
        markDirty();
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
            terms_html: data.terms_html || '',
        }));
        // Consistent with handleAddPackageFromCalculator (K-01): drop empty placeholder rows.
        // If after filtering nothing is left, provide a single empty row as fallback.
        const filtered = data.items.filter(i => !isEmptyRow(i));
        setItems(filtered.length > 0 ? filtered : [{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
        setDiscounts(data.discounts);
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
                    items: [...items, ...discounts.map(d => ({...d, qty: 1}))].map((i: InvoiceItem | InvoiceDiscount) => ({
                        ...i,
                        price: i.type === 'discount_percent' ? Number((i.price * 100).toFixed(4)) : Math.round(i.price * 100),
                    })),
                }),
            });
            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                let niceMsg = errData.message || errData.error || t`Fehler beim Generieren (Bitte Eingaben prüfen).`;
                if (errData.errors) {
                    const firstErr = Object.values(errData.errors)[0];
                    if (Array.isArray(firstErr)) niceMsg = firstErr[0];
                }
                if (niceMsg.includes('items.') && niceMsg.includes('description')) niceMsg = t`Bitte alle Titel/Namen bei den Leistungen ausfüllen.`;
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
            setIsDirty(false);
            setUnsavedChanges(false);
            showToast('success', t`Dokument wurde erstellt.`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler`);
        }
        setIsGenerating(false);
    };

    const subtotal = items.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const total = discounts.reduce(
        (t, d) => (d.type === 'discount_percent' ? t * (1 - d.price / 100) : t - d.price),
        subtotal,
    );
    const hasInvalidItems = items.some(i => !i.description.trim() || i.qty <= 0) || discounts.some(d => !d.description.trim());
    const isFormValid = items.length > 0 && total >= 0 && !hasInvalidItems;

    return {
        // State
        formData,
        items,
        discounts,
        dueDateOption,
        isGenerating,
        isOffer,
        isDirty,

        // Handlers
        handleUpdateField,
        handleOptionChange,
        handleServiceDateManualChange,
        handleItemChange,
        handleDiscountChange,
        addItem,
        removeItem,
        moveItemUp,
        moveItemDown,
        addDiscount,
        removeDiscount,
        moveDiscountUp,
        moveDiscountDown,
        handleAddPackageFromCalculator,
        handleMultiUpdate,
        loadExtractedData,
        handleDownload,

        // Derived
        subtotal,
        total,
        hasInvalidItems,
        isFormValid,
    };
}
