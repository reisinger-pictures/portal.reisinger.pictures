import {useState} from 'react';
import {t} from "@lingui/core/macro";
import {useUI} from '../ui/components/UIContext';

export function useInvoiceDragDrop(isOffer: boolean, onFileDrop: (file: File) => void) {
    const {showToast} = useUI();
    const [isDragging, setIsDragging] = useState(false);

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
            await onFileDrop(file);
        } else if (file) {
            showToast('error', t`Bitte lade eine gültige PDF-Datei hoch.`);
        }
    };

    return {isDragging, handleDragOver, handleDragLeave, handleDrop};
}
