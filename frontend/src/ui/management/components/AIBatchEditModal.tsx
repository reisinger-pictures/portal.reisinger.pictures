import { useState, useEffect, useRef } from 'react';
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
    location: string;
    city: string;
    state: string;
    country: string;
    iso_country: string;
}

export default function AIBatchEditModal({ isOpen, onClose, photos }: Props) {
    const { isAvailable, modelId, baseUrl, updateBaseUrl, generateMetadata } = useLMStudio(isOpen);
    const { updateMetadata: updatePhotoMeta } = usePhoto();
    const { showToast } = useUI();

    const [globalContext, setGlobalContext] = useState('');
    const [rows, setRows] = useState<RowState[]>([]);
    const [isGeneratingAll, setIsGeneratingAll] = useState(false);
    const [progress, setProgress] = useState(0);
    const abortControllerRef = useRef<AbortController | null>(null);

    const [prevIsOpen, setPrevIsOpen] = useState(false);
    const [prevPhotos, setPrevPhotos] = useState<Photo[]>([]);

    // Derived State Pattern: Sync props to state directly during render
    if (isOpen !== prevIsOpen || photos !== prevPhotos) {
        setPrevIsOpen(isOpen);
        setPrevPhotos(photos);
        
        if (isOpen) {
            setRows(photos.map(p => ({
                photoId: p.id,
                specificContext: '',
                isGenerating: false,
                isSaving: false,
                title: p.title || '',
                description: p.description || '',
                keywords: p.keywords || '',
                location: p.location || '',
                city: p.city || '',
                state: p.state || '',
                country: p.country || '',
                iso_country: p.iso_country || ''
            })));
            abortControllerRef.current = new AbortController();
        } else {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
                abortControllerRef.current = null;
            }
            setIsGeneratingAll(false);
        }
    }

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            if (abortControllerRef.current) {
                abortControllerRef.current.abort();
            }
        };
    }, []);

    if (!isOpen) return null;

    const processRow = async (index: number, currentRows: RowState[], signal?: AbortSignal): Promise<RowState[]> => {
        const row = currentRows[index];
        const photo = photos.find(p => p.id === row.photoId);
        if (!photo || !photo.url) return currentRows;

        let updatedRows = [...currentRows];
        updatedRows[index] = { ...updatedRows[index], isGenerating: true };
        setRows(updatedRows);

        try {
            const aiData = await generateMetadata(photo.url, globalContext, row.specificContext, signal);
            let locData = {
                location: aiData.location || row.location,
                city: aiData.detected_city || row.city,
                state: row.state,
                country: row.country,
                iso_country: row.iso_country
            };

            if (aiData.detected_city) {
                try {
                    const locRes = await fetch(`/api/search/locations?type=city&q=${encodeURIComponent(aiData.detected_city)}`, { credentials: 'include', signal });
                    if (locRes.ok) {
                        const locs: LocationResult[] = await locRes.json();
                        if (locs.length > 0) {
                            locData = {
                                ...locData,
                                city: locs[0].name,
                                state: locs[0].state || '',
                                country: locs[0].country || '',
                                iso_country: locs[0].iso_country || ''
                            };
                        } else {
                            showToast('info', `Stadt "${aiData.detected_city}" wurde nicht in der Datenbank gefunden.`);
                        }
                    }
                } catch (e: unknown) {
                    const isAbort = e instanceof Error && e.name === 'AbortError';
                    if (!isAbort) {
                        console.warn("Location fallback failed for city:", aiData.detected_city, e);
                        showToast('error', `Fehler bei der Stadt-Validierung für "${aiData.detected_city}".`);
                    }
                }
            }

            if (signal?.aborted) return currentRows;

            updatedRows = [...updatedRows];
            updatedRows[index] = {
                ...updatedRows[index],
                isGenerating: false,
                title: aiData.title || updatedRows[index].title,
                description: aiData.description || updatedRows[index].description,
                keywords: aiData.keywords || updatedRows[index].keywords,
                ...locData
            };
            setRows(updatedRows);
            return updatedRows;
        } catch (e: unknown) {
            const isAbort = e instanceof Error && e.name === 'AbortError';
            if (!isAbort) {
                showToast('error', 'Fehler bei der KI Generierung für ein Bild.');
                updatedRows = [...updatedRows];
                updatedRows[index] = { ...updatedRows[index], isGenerating: false };
                setRows(updatedRows);
            }
            return updatedRows;
        }
    };

    const handleGenerate = async (index: number) => {
        await processRow(index, rows, abortControllerRef.current?.signal);
    };

    const handleGenerateAll = async () => {
        setIsGeneratingAll(true);
        setProgress(0);
        let currentRows = [...rows];
        for (let i = 0; i < currentRows.length; i++) {
            if (abortControllerRef.current?.signal.aborted) break;
            if (!currentRows[i].title) {
                currentRows = await processRow(i, currentRows, abortControllerRef.current?.signal);
            }
            setProgress(Math.round(((i + 1) / currentRows.length) * 100));
        }
        if (!abortControllerRef.current?.signal.aborted) {
            setIsGeneratingAll(false);
            setProgress(0);
            showToast('success', 'Batch-Generierung abgeschlossen.');
        }
    };

    const handleSave = async (index: number) => {
        const row = rows[index];
        setRows(prev => prev.map((r, i) => i === index ? { ...r, isSaving: true } : r));
        try {
            await updatePhotoMeta(row.photoId, {
                title: row.title,
                description: row.description,
                keywords: row.keywords,
                location: row.location,
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
                <div className="flex justify-between items-center mb-2 mr-8">
                    <h3 className="font-bold text-2xl flex items-center gap-2">
                        <span className="iconify mdi--robot-outline text-primary"></span> KI Batch-Edit (Lokal)
                    </h3>
                    <button
                        onClick={handleGenerateAll}
                        disabled={!isAvailable || isGeneratingAll}
                        className="btn btn-primary btn-sm"
                    >
                        {isGeneratingAll ? <span className="loading loading-spinner loading-xs"></span> : <span className="iconify mdi--auto-fix"></span>}
                        Alle generieren (leere)
                    </button>
                </div>

                {isGeneratingAll && (
                    <div className="mb-4">
                        <progress className="progress progress-primary w-full" value={progress} max="100"></progress>
                        <div className="text-xs text-center mt-1 opacity-70">{progress}% abgeschlossen</div>
                    </div>
                )}
                
                <div className="flex flex-col lg:flex-row items-start lg:items-center gap-4 mb-4 bg-base-100 p-4 rounded-box shadow-sm border border-base-300">
                    <div className="flex-1 w-full">
                        <label className="label py-0"><span className="label-text font-bold">Globaler Kontext (Für alle Bilder)</span></label>
                        <input type="text" value={globalContext} onChange={e => setGlobalContext(e.target.value)} placeholder="z.B. Sommerfest der Firma XYZ in Wien, 2026" className="input input-sm input-bordered w-full" />
                    </div>
                    <div className="flex-1 w-full border-t lg:border-t-0 lg:border-l border-base-300 pt-2 lg:pt-0 lg:pl-4">
                        <label className="label py-0"><span className="label-text font-bold">LM Studio API URL</span></label>
                        <div className="flex gap-2 items-center">
                            <input type="text" value={baseUrl} onChange={e => updateBaseUrl(e.target.value)} placeholder="http://127.0.0.1:1234" className="input input-sm input-bordered flex-1" />
                            {isAvailable ? <div className="badge badge-success badge-sm shrink-0">{modelId}</div> : <div className="badge badge-error badge-sm shrink-0">Nicht erreichbar</div>}
                        </div>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto bg-base-100 rounded-box border border-base-300 p-2 space-y-2">
                    {rows.map((row, idx) => {
                        const p = photos.find(x => x.id === row.photoId);
                        const isTitleTooLong = row.title.length > 120;
                        return (
                            <div key={row.photoId} className="flex flex-col md:flex-row gap-4 p-3 bg-base-200/50 rounded-box border border-base-300">
                                <div className="w-full md:w-32 shrink-0">
                                    <img src={p?.thumb_url} className="w-full h-auto object-cover rounded shadow-sm aspect-video" alt="Thumb" />
                                </div>
                                <div className="flex-1 grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <input type="text" value={row.specificContext} onChange={e => updateRowField(idx, 'specificContext', e.target.value)} placeholder="Spezifischer Bild-Kontext (Optional)" className="input input-sm input-bordered md:col-span-2" />
                                    <div className="relative">
                                        <input type="text" value={row.title} onChange={e => updateRowField(idx, 'title', e.target.value)} placeholder="Titel" className={`input input-sm input-bordered w-full pr-14 ${isTitleTooLong ? 'input-error text-error' : ''}`} />
                                        <span className={`absolute right-2 top-1.5 text-xs ${isTitleTooLong ? 'text-error font-bold' : 'opacity-50'}`}>${row.title.length}/120</span>
                                    </div>
                                    <input type="text" value={row.keywords} onChange={e => updateRowField(idx, 'keywords', e.target.value)} placeholder="Keywords" className="input input-sm input-bordered" />
                                    <textarea value={row.description} onChange={e => updateRowField(idx, 'description', e.target.value)} placeholder="Beschreibung" className="textarea textarea-bordered textarea-sm md:col-span-2 h-16 leading-tight"></textarea>
                                    <div className="grid grid-cols-2 gap-2 md:col-span-2">
                                        <input type="text" value={row.location} onChange={e => updateRowField(idx, 'location', e.target.value)} placeholder="Ort/Gebäude" className="input input-sm input-bordered" />
                                        <input type="text" value={row.city} onChange={e => updateRowField(idx, 'city', e.target.value)} placeholder="Stadt" className="input input-sm input-bordered" />
                                    </div>
                                </div>
                                <div className="flex flex-col gap-2 w-full md:w-32 shrink-0 justify-end">
                                    <button onClick={() => handleGenerate(idx)} disabled={!isAvailable || row.isGenerating || isGeneratingAll} className="btn btn-sm btn-primary w-full">
                                        {row.isGenerating ? <span className="loading loading-spinner loading-xs"></span> : 'KI Generieren'}
                                    </button>
                                    <button onClick={() => handleSave(idx)} disabled={row.isSaving || isTitleTooLong} className="btn btn-sm btn-outline w-full">
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
