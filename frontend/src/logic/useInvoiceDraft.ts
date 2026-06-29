import {useState, useCallback} from 'react';
import {useUI} from '../ui/components/UIContext';
import {DocumentFormData, InvoiceDiscount, InvoiceItem} from '../api';
import {formatDateToDE, formatLocaleDate} from './utils';

export function useInvoiceDraft(type: 'invoice' | 'offer' = 'invoice') {
    const {showToast} = useUI();
    const isOffer = type === 'offer';

    const getOfferValidUntil = () => {
        const d = new Date();
        d.setDate(d.getDate() + 14);
        return 'Gültig bis ' + formatLocaleDate(d);
    };
    const getInvoiceDefaultDue = () => 'Zahlbar sofort nach Erhalt der Rechnung.';

    const [isGenerating, setIsGenerating] = useState(false);
    const [dueDateOption, setDueDateOption] = useState('0');
    const [serviceDateDirty, setServiceDateDirty] = useState(false);

    const [formData, setFormData] = useState<DocumentFormData>(() => ({
        type,
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
        terms_html: '',
    }));

    const [items, setItems] = useState<InvoiceItem[]>([{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    const [discounts, setDiscounts] = useState<InvoiceDiscount[]>([]);

    const handleUpdateField = useCallback((field: string, value: string) => {
        setFormData(p => {
            const next = {...p, [field]: value};
            if (field === 'date' && !isOffer && !serviceDateDirty) {
                next.service_date = formatDateToDE(value);
            }
            return next;
        });
    }, [isOffer, serviceDateDirty]);

    const handleOptionChange = useCallback((opt: string) => {
        setDueDateOption(opt);
        if (opt === '0') {
            handleUpdateField('due_date', isOffer ? getOfferValidUntil() : getInvoiceDefaultDue());
        }
    }, [isOffer, handleUpdateField]);

    const handleServiceDateManualChange = useCallback((val: string) => {
        setServiceDateDirty(true);
        handleUpdateField('service_date', val);
    }, [handleUpdateField]);

    const handleItemChange = useCallback((index: number, field: string, value: string | number) => {
        setItems(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    }, []);

    const handleDiscountChange = useCallback((index: number, field: string, value: string | number) => {
        setDiscounts(prev => {
            const newArr = [...prev];
            newArr[index] = {...newArr[index], [field]: value};
            return newArr;
        });
    }, []);

    const addItem = useCallback(() => {
        setItems(prev => [...prev, {type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    }, []);

    const removeItem = useCallback((index: number) => {
        setItems(prev => prev.filter((_, i) => i !== index));
    }, []);

    const moveItemUp = useCallback((index: number) => {
        if (index === 0) return;
        setItems(prev => {
            const newItems = [...prev];
            const temp = newItems[index - 1];
            newItems[index - 1] = newItems[index];
            newItems[index] = temp;
            return newItems;
        });
    }, []);

    const moveItemDown = useCallback((index: number) => {
        setItems(prev => {
            if (index === prev.length - 1) return prev;
            const newItems = [...prev];
            const temp = newItems[index + 1];
            newItems[index + 1] = newItems[index];
            newItems[index] = temp;
            return newItems;
        });
    }, []);

    const addDiscount = useCallback(() => {
        setDiscounts(prev => [...prev, {type: 'discount_fixed', description: '', notes: '', price: 0}]);
    }, []);

    const removeDiscount = useCallback((index: number) => {
        setDiscounts(prev => prev.filter((_, i) => i !== index));
    }, []);

    const handleAddPackageFromCalculator = useCallback((newItem: InvoiceItem, newDiscount: InvoiceDiscount | null) => {
        setItems(prev => [...prev, newItem]);
        if (newDiscount) {
            setDiscounts(prev => [...prev, newDiscount]);
        }
    }, []);

    const handleMultiUpdate = useCallback((updates: Record<string, string>) => {
        setFormData(prev => ({...prev, ...updates}));
    }, []);

    const loadExtractedData = useCallback((data: {
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
        setItems(data.items.length > 0 ? data.items : [{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
        setDiscounts(data.discounts);
    }, []);

    const handleDownload = useCallback(async (e: React.FormEvent) => {
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
    }, [formData, items, discounts, isOffer, showToast]);

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
