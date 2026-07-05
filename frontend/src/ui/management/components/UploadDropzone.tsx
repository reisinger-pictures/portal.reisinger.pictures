import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { useUI } from '../../components/UIContext';

interface Props {
    galleryId: string;
    onUploadComplete: () => void;
}

export default function UploadDropzone({ galleryId, onUploadComplete }: Props) {
    const [uploading, setUploading] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const [replaceExisting, setReplaceExisting] = useState(false);
    const { showToast } = useUI();

    const handleWebUpload = async (files: FileList | null) => {
        if (!files) return;
        setUploading(true);
        let successCount = 0;
        
        for (const file of Array.from(files)) {
            const formData = new FormData();
            formData.append('gallery_id', galleryId);
            formData.append('lr_uuid', 'web-' + Math.random().toString(36).substring(2, 15));
            formData.append('replace', replaceExisting ? '1' : '0');
            formData.append('file', file);
            try {
                const res = await fetch('/api/management/upload', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                if (res.ok) successCount++;
            } catch (err) {
                console.error(err);
            }
        }
        
        setUploading(false);
        if (successCount > 0) {
            showToast('success', t`${successCount} Bild(er) hochgeladen`);
            onUploadComplete();
        }
    };

    return (
        <div className="mb-8">
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setIsDragging(true);
                }}
                onDragLeave={() => setIsDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setIsDragging(false);
                    handleWebUpload(e.dataTransfer.files);
                }}
                className={`p-6 md:p-10 border-2 border-dashed rounded-box flex flex-col items-center justify-center text-center transition-colors ${isDragging ? 'border-primary bg-primary/10' : 'border-base-content/30 bg-base-200'}`}
            >
                <span className="iconify mdi--cloud-upload text-5xl mb-3 text-primary"></span>
                <h3 className="font-bold text-xl mb-1"><Trans>Bilder hierher ziehen</Trans></h3>
                <p className="text-sm opacity-70 mb-6"><Trans>oder auf den Button klicken, um Dateien auszuwählen</Trans></p>
                
                <input 
                    type="file" 
                    multiple 
                    accept="image/jpeg, .jpg, .jpeg" 
                    onChange={(e) => handleWebUpload(e.target.files)}
                    disabled={uploading}
                    className="file-input-bordered file-input-primary file-input w-full max-w-xs"
                />
                {uploading && <div className="mt-4 text-primary font-bold animate-pulse"><Trans>Lade hoch... Bitte warten.</Trans></div>}
            </div>
            <div className="mt-3 flex justify-center">
                <label className="cursor-pointer label flex items-center gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                    <input type="checkbox" checked={replaceExisting} onChange={e => setReplaceExisting(e.target.checked)} className="checkbox checkbox-primary shrink-0" />
                    <span className="label-text opacity-80"><Trans>Bilder mit gleichem Dateinamen überschreiben</Trans></span>
                </label>
            </div>
        </div>
    );
}
