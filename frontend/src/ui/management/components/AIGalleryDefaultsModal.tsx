import { useState } from 'react';
import { useAI } from '../../../logic/useAI';
import { useUI } from '../../components/UIContext';
import { IptcData } from '../../../logic/usePhoto';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onApply: (data: Partial<IptcData>) => void;
}

export default function AIGalleryDefaultsModal({ isOpen, onClose, onApply }: Props) {
    const { isAvailable, generateMetadataFromText } = useAI();
    const { showToast } = useUI();
    const [galleryDescription, setGalleryDescription] = useState('');
    const [isGenerating, setIsGenerating] = useState(false);
    const [result, setResult] = useState<{
        title: string; description: string; keywords: string; location: string; city: string;
    } | null>(null);

    if (!isOpen) return null;

    const handleGenerate = async () => {
        if (!galleryDescription.trim()) return;
        setIsGenerating(true);
        setResult(null);
        try {
            const data = await generateMetadataFromText(galleryDescription);
            setResult({
                title: data.title || '',
                description: data.description || '',
                keywords: data.keywords || '',
                location: data.location || '',
                city: data.detected_city || ''
            });
            showToast('success', 'KI-Vorschlag geladen.');
        } catch {
            showToast('error', 'KI-Generierung fehlgeschlagen.');
        }
        setIsGenerating(false);
    };

    const handleApply = () => {
        if (result) {
            onApply(result);
            onClose();
        }
    };

    return (
        <dialog className="modal modal-open z-[70]">
            <div className="modal-box max-w-xl relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--robot-outline text-primary"></span> KI-Vorschlag für Vorgaben
                </h3>
                <p className="text-sm opacity-70 mb-4">
                    Beschreibe die Galerie — die KI generiert Vorschläge für Titel, Beschreibung und Keywords (nur Text, keine Bildanalyse).
                </p>

                <textarea
                    value={galleryDescription}
                    onChange={e => setGalleryDescription(e.target.value)}
                    placeholder="z.B. Hochzeitsreportage im Wiener Burggarten, Mai 2026, Paar vor historischer Kulisse..."
                    className="textarea textarea-bordered w-full h-24 mb-4"
                    disabled={isGenerating}
                />

                <button
                    type="button"
                    onClick={handleGenerate}
                    disabled={isGenerating || !galleryDescription.trim() || !isAvailable}
                    className="btn btn-primary btn-sm w-full mb-4"
                >
                    {isGenerating ? (
                        <span className="loading loading-spinner loading-xs"></span>
                    ) : (
                        <><span className="iconify mdi--auto-fix"></span> KI generieren</>
                    )}
                </button>

                {result && (
                    <div className="bg-base-200 p-3 rounded-box border border-base-300 space-y-2 mb-4">
                        <p className="text-xs font-bold opacity-70">Vorschlag:</p>
                        <div className="text-sm"><span className="font-semibold">Titel:</span> {result.title || '—'}</div>
                        <div className="text-sm"><span className="font-semibold">Beschreibung:</span> {result.description || '—'}</div>
                        <div className="text-sm"><span className="font-semibold">Keywords:</span> {result.keywords || '—'}</div>
                        <div className="text-sm"><span className="font-semibold">Ort:</span> {result.location || '—'}</div>
                        <button
                            type="button"
                            onClick={handleApply}
                            className="btn btn-primary btn-sm w-full mt-2"
                        >
                            Vorschlag übernehmen
                        </button>
                    </div>
                )}

                <div className="flex justify-end gap-3">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={onClose}>Schliessen</button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </dialog>
    );
}
