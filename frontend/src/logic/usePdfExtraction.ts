import {useState, useCallback} from 'react';
import {useUI} from '../ui/components/UIContext';
import {InvoiceDiscount, InvoiceItem} from '../api';

export interface ExtractedData {
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
}

export function usePdfExtraction(onDataExtracted: (data: ExtractedData) => void) {
    const {showToast} = useUI();
    const [isExtracting, setIsExtracting] = useState(false);

    const processPdfFile = useCallback(async (file: File) => {
        const fd = new FormData();
        fd.append('pdf', file);
        setIsExtracting(true);

        try {
            const res = await fetch('/api/management/invoices/extract-offer', {
                method: 'POST',
                body: fd,
                headers: {'Accept': 'application/json'},
                credentials: 'include',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || data.message || 'Fehler beim Auslesen.');

            const items: InvoiceItem[] = data.items
                ?.filter((i: InvoiceItem) => i.type === 'item')
                .map((i: InvoiceItem) => ({
                    ...i,
                    price: i.price / 100,
                })) || [];

            const discounts: InvoiceDiscount[] = data.items
                ?.filter((i: InvoiceDiscount) => i.type !== 'item')
                .map((i: InvoiceDiscount) => ({
                    ...i,
                    price: i.price / 100,
                })) || [];

            onDataExtracted({
                customer_name: data.customer_name || '',
                customer_company: data.customer_company || '',
                customer_street: data.customer_street || '',
                customer_zip: data.customer_zip || '',
                customer_city: data.customer_city || '',
                customer_country: data.customer_country || '',
                customer_email: data.customer_email || '',
                customer_uid: data.customer_uid || '',
                terms_html: data.terms_html || '',
                items,
                discounts,
            });

            showToast('success', 'Angebotsdaten erfolgreich übernommen!');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        } finally {
            setIsExtracting(false);
        }
    }, [onDataExtracted, showToast]);

    const handleFileUpload = useCallback(async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        await processPdfFile(file);
        e.target.value = '';
    }, [processPdfFile]);

    return {isExtracting, processPdfFile, handleFileUpload};
}
