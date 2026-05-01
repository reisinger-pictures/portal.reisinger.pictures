import { useState, useEffect } from 'react';
import { Photo } from '../../../logic/useGallery';
import { useLMStudio } from '../../../logic/useLMStudio';
import { usePhoto } from '../../../logic/usePhoto';
import { useUI } from '../../components/UIContext';
import { LocationResult } from '../../../logic/useLocations';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    photos: Photo[];
    galleryId: string;
}

interface RowState {
    photoId: string;
    specificContext: string;
    isGenerating: boolean;
    isSaving: boolean;
    title: string;
    description: string;
    keywords: string;
    city: string;
    state: string;
    country: string;
    iso_country: string;
}

export default function AIBatchEditModal({ isOpen, onClose, photos }: Props) {
    const { isAvailable, modelId, generateMetadata } = useLMStudio();
    const { updateMetadata } = usePhoto();
    const { showToast } = useUI();

    const [globalContext, setGlobalContext] = useState('');
    const [rows, setRows] = useState<RowState[]>([]);

    useEffect(() => {
        if (isOpen) {
            setRows(photos.map(p => ({
                photoId: p.id,
                specificContext: '',
                isGenerating: false,
                isSaving: false,
                title: p.title || '',
                description: p.description || '',
                keywords: p.keywords || '',
                city: p.city || '',
                state: p.state || '',
                country: p.country || '',
                iso_country: p.iso_country || ''
            })));
        }
    }, [isOpen, photos]);

    if (!isOpen) return null;

    const handleGenerate = async (index: number) => {
        const row = rows[index];
        const photo = photos.find(p => p.id === row.photoId);
        if (!photo || !photo.url) return;

        setRows(prev => prev.map((r, i) => i === index ? { ...r, isGenerating: true } : r));

        try {
            const aiData = await generateMetadata(photo.url, globalContext, row.specificContext);
            let locData = { city: aiData.detected_city || '', state: row.state, country: row.country, iso_country: row.iso_country };

            if (aiData.detected_city) {
                try {
                    const locRes = await fetch(`/api/search/locations?type=city&q=${encodeURIComponent(aiData.detected_city)}`, { credentials: 'include' });
                    if (locRes.ok) {
                        const locs: LocationResult[] = await locRes.json();
                        if (locs.length > 0) {
                            locData = {
                                city: locs[0].name,
                                state: locs[0].state || '',
                                country: locs[0].country || '',
                                iso_country: locs[0].iso_country || ''
                            };
                        }
                    }
                } catch (e) { console.error("Location fallback failed", e); }
            }

            setRows(prev => prev.map((r, i) => i === index ? {
                ...r,
                isGenerating: false,
                title: aiData.title || r.title,
                description: aiData.description || r.description,
                keywords: aiData.keywords || r.keywords,
                ...locData
            } : r));
        } catch (e) {
            showToast('error', 'Fehler bei der KI Generierung.');
            setRows(prev => prev.map((r, i) => i === index ? { ...r, isGenerating: false } : r));
        }
    };

    const handleSave = async (index: number) => {
        const row = rows[index];
        setRows(prev => prev.map((r, i) => i === index ? { ...r, isSaving: true } : r));
        try {
            await updateMetadata(row.photoId, {
                title: row.title,
                description: row.description,
                keywords: row.keywords,
                city: row.city,
                state: row.state,
                country: row.country,
                iso_country: row.iso_country
            });
            showToast('success', 'Gespeichert!');
        } catch {
            showToast('error', 'Fehler beim Speichern.');
        } finally {
            setRows(prev => prev.map((r, i) => i === index ? { ...r, isSaving: false } : r));
        }
    };

    const updateRowField = (index: number, field: keyof RowState, value: string) => {
        setRows(prev => prev.map((r, i) => i === index ? { ...r, [field]: value } : r));
    };

    return (
        <div className="modal modal-open z-[90]">
            <div className="modal-box w-11/12 max-w-7xl h-[90vh] flex flex-col relative bg-base-200">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-2xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--robot-outline text-primary"></span> KI Batch-Edit (Lokal)
                </h3>
                
                <div className="flex items-center gap-4 mb-4 bg-base-100 p-4 rounded-box shadow-sm border border-base-300">
                    <div className="flex-1">
                        <label className="label py-0"><span className="label-text font-bold">Globaler Kontext (Für alle Bilder)</span></label>
                        <input type="text" value={globalContext} onChange={e => setGlobalContext(e.target.value)} placeholder="z.B. Sommerfest der Firma XYZ in Wien, 2026" className="input input-sm input-bordered w-full" />
                    </div>
                    <div className="text-right shrink-0 border-l border-base-300 pl-4">
                        <p className="text-xs font-bold opacity-70">LM Studio Status</p>
                        {isAvailable ? <div className="badge badge-success badge-sm mt-1">{modelId}</div> : <div className="badge badge-error badge-sm mt-1">Nicht erreichbar</div>}
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto bg-base-100 rounded-box border border-base-300 p-2 space-y-2">
                    {rows.map((row, idx) => {
                        const p = photos.find(x => x.id === row.photoId);
                        return (
                            <div key={row.photoId} className="flex flex-col md:flex-row gap-4 p-3 bg-base-200/50 rounded-box border border-base-300">
                                <div className="w-full md:w-32 shrink-0">
                                    <img src={p?.thumb_url} className="w-full h-auto object-cover rounded shadow-sm aspect-video" alt="Thumb" />
                                </div>
                                <div className="flex-1 grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <input type="text" value={row.specificContext} onChange={e => updateRowField(idx, 'specificContext', e.target.value)} placeholder="Spezifischer Bild-Kontext (Optional)" className="input input-sm input-bordered md:col-span-2" />
                                    <input type="text" value={row.title} onChange={e => updateRowField(idx, 'title', e.target.value)} placeholder="Titel" className="input input-sm input-bordered" />
                                    <input type="text" value={row.keywords} onChange={e => updateRowField(idx, 'keywords', e.target.value)} placeholder="Keywords" className="input input-sm input-bordered" />
                                    <textarea value={row.description} onChange={e => updateRowField(idx, 'description', e.target.value)} placeholder="Beschreibung" className="textarea textarea-bordered textarea-sm md:col-span-2 h-16 leading-tight"></textarea>
                                </div>
                                <div className="flex flex-col gap-2 w-full md:w-32 shrink-0">
                                    <button onClick={() => handleGenerate(idx)} disabled={!isAvailable || row.isGenerating} className="btn btn-sm btn-primary w-full">
                                        {row.isGenerating ? <span className="loading loading-spinner loading-xs"></span> : 'KI Generieren'}
                                    </button>
                                    <button onClick={() => handleSave(idx)} disabled={row.isSaving} className="btn btn-sm btn-outline w-full">
                                        {row.isSaving ? <span className="loading loading-spinner loading-xs"></span> : 'Speichern'}
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}