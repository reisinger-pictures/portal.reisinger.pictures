import {useState, useCallback} from 'react';
import {useUI} from '../ui/components/UIContext';

export function useInvoiceDragDrop(isOffer: boolean, onFileDrop: (file: File) => void) {
    const {showToast} = useUI();
    const [isDragging, setIsDragging] = useState(false);

    const handleDragOver = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        if (!isOffer) setIsDragging(true);
    }, [isOffer]);

    const handleDragLeave = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
    }, []);

    const handleDrop = useCallback(async (e: React.DragEvent) => {
        e.preventDefault();
        setIsDragging(false);
        if (isOffer) return;

        const file = e.dataTransfer.files?.[0];
        if (file && file.type === 'application/pdf') {
            await onFileDrop(file);
        } else if (file) {
            showToast('error', 'Bitte lade eine gültige PDF-Datei hoch.');
        }
    }, [isOffer, onFileDrop, showToast]);

    return {isDragging, handleDragOver, handleDragLeave, handleDrop};
}
