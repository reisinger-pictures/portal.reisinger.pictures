import { useState } from 'react';
import { t } from "@lingui/core/macro";
import { useUI } from '../ui/components/UIContext';
import { ExtractedData } from './usePdfExtraction';

export function useProjectPdfDrop(onExtracted: (data: ExtractedData) => void) {
    const { showToast } = useUI();
    const [isDragging, setIsDragging] = useState(false);
    const [isExtracting, setIsExtracting] = useState(false);

    const processFile = async (file: File) => {
        const fd = new FormData();
        fd.append('pdf', file);
        setIsExtracting(true);
        try {
            const res = await fetch('/api/management/invoices/extract-offer', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json' },
                credentials: 'include',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || data.message || t`Fehler beim Auslesen.`);
            onExtracted({
                customer_name: data.customer_name || '',
                customer_company: data.customer_company || '',
                customer_street: data.customer_street || '',
                customer_zip: data.customer_zip || '',
                customer_city: data.customer_city || '',
                customer_country: data.customer_country || '',
                customer_email: data.customer_email || '',
                customer_uid: data.customer_uid || '',
                terms_html: data.terms_html || '',
                items: [],
                discounts: [],
            });
            showToast('success', t`Angebot erkannt – Felder vorbefüllt`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        } finally {
            setIsExtracting(false);
        }
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
    };

    const handleDrop = async (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        const file = e.dataTransfer.files?.[0];
        if (file && file.type === 'application/pdf') {
            await processFile(file);
        } else if (file) {
            showToast('error', t`Bitte lade eine gültige PDF-Datei hoch.`);
        }
    };

    return { isDragging, isExtracting, handleDragOver, handleDragLeave, handleDrop };
}