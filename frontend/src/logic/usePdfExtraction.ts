import {useState} from 'react';
import {t} from "@lingui/core/macro";
import {useUI} from '../ui/components/UIContext';
import {apiUpload, InvoiceDiscount, InvoiceItem} from '../api';

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

export interface OfferExtractionResponse {
    customer_name?: string;
    customer_company?: string;
    customer_street?: string;
    customer_zip?: string;
    customer_city?: string;
    customer_country?: string;
    customer_email?: string;
    customer_uid?: string;
    terms_html?: string;
    items?: Array<InvoiceItem | InvoiceDiscount>;
    error?: string;
    message?: string;
}

export function usePdfExtraction(onDataExtracted: (data: ExtractedData) => void) {
    const {showToast} = useUI();
    const [isExtracting, setIsExtracting] = useState(false);

    const processPdfFile = async (file: File) => {
        const fd = new FormData();
        fd.append('pdf', file);
        setIsExtracting(true);

        try {
            const data = await apiUpload<OfferExtractionResponse>('/api/management/invoices/extract-offer', fd);

            const items: InvoiceItem[] = data.items
                ?.filter((row): row is InvoiceItem => row.type === 'item')
                .map((row) => ({
                    ...row,
                    price: row.price / 100,
                })) || [];

            const discounts: InvoiceDiscount[] = data.items
                ?.filter((row): row is InvoiceDiscount => row.type !== 'item')
                .map((row) => ({
                    ...row,
                    price: row.price / 100,
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

            showToast('success', t`Angebotsdaten erfolgreich übernommen!`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        } finally {
            setIsExtracting(false);
        }
    };

    const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        await processPdfFile(file);
        e.target.value = '';
    };

    return {isExtracting, processPdfFile, handleFileUpload};
}
