import { useEffect, useState } from 'react';
import ErrorMessage from '../../components/ErrorMessage';
import { RatingData } from '../../../api';

interface Props {
    galleryId: string;
    isOpen: boolean;
    onClose: () => void;
}



export default function RatingStatusModal({ galleryId, isOpen, onClose }: Props) {
    const [ratingsData, setRatingsData] = useState<Array<RatingData>>([]);
    const [ratingStatusData, setRatingStatusData] = useState<Array<RatingData>>([]);
    const [totalGalleryPhotos, setTotalGalleryPhotos] = useState(0);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!isOpen) return;

        let isMounted = true;
        setIsLoading(true);
        setError(false);

        const fetchRatings = async () => {
            try {
                const headers = { 'Accept': 'application/json' };
                const [resExport, resStatus] = await Promise.all([
                    fetch('/api/management/galleries/' + galleryId + '/export', { headers, credentials: 'include' }),
                    fetch('/api/management/galleries/' + galleryId + '/rating-status', { headers, credentials: 'include' })
                ]);

                if (!resExport.ok || !resStatus.ok) throw new Error('API Error');

                const dataExport = await resExport.json();
                const dataStatus = await resStatus.json();

                if (isMounted) {
                    setRatingsData(dataExport);
                    setRatingStatusData(dataStatus.users);
                    setTotalGalleryPhotos(dataStatus.total_photos);
                    setIsLoading(false);
                }
            } catch {
                if (isMounted) {
                    setError(true);
                    setIsLoading(false);
                }
            }
        };

        fetchRatings();

        return () => { isMounted = false; };
    }, [isOpen, galleryId]);

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-5xl relative flex flex-col max-h-[90vh]">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-2xl mb-6 shrink-0">Bewertungen & Status</h3>
                
                {isLoading ? (
                    <div className="flex-1 flex items-center justify-center p-10"><span className="loading loading-spinner loading-lg text-primary"></span></div>
                ) : error ? (
                    <ErrorMessage message="Fehler beim Laden der Bewertungen." />
                ) : (
                    <div className="flex-1 overflow-y-auto pr-2 space-y-8">
                        <div>
                            <h4 className="font-bold text-lg mb-3 flex items-center gap-2">
                                <span className="iconify mdi--account-group"></span> Beteiligte Personen
                            </h4>
                            <div className="overflow-x-auto border border-base-300 rounded-box">
                                <table className="table table-zebra w-full">
                                    <thead className="bg-base-200">
                                        <tr>
                                            <th>Name</th>
                                            <th>Status</th>
                                            <th>Fortschritt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {ratingStatusData.map(u => (
                                            <tr key={u.user_id}>
                                                <td className="font-bold">
                                                    {u.name}
                                                    {u.email && !u.email.includes('@invite.local') && <span className="block text-sm opacity-70 font-normal">{u.email}</span>}
                                                    {u.email && u.email.includes('@invite.local') && <span className="block text-sm opacity-50 font-normal">Via Magic Link</span>}
                                                </td>
                                                <td className="whitespace-nowrap">{u.rated_count} von {totalGalleryPhotos} Bildern</td>
                                                <td className="w-1/3 min-w-[100px]">
                                                    <progress className="progress progress-primary w-full" value={u.rated_count} max={totalGalleryPhotos || 1}></progress>
                                                </td>
                                            </tr>
                                        ))}
                                        {ratingStatusData.length === 0 && (
                                            <tr><td colSpan={3} className="text-center py-6 opacity-50">Es sind aktuell keine Personen für diese Galerie freigeschaltet.<br/>Erstelle einen Einladungslink oder weise Nutzer zu, um Gästen Zugriff zu gewähren.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h4 className="font-bold text-lg mb-3 flex items-center gap-2 mt-4">
                                <span className="iconify mdi--image-multiple"></span> Detaillierte Auswertungen (Bild-Bewertungen)
                            </h4>
                            <div className="overflow-x-auto border border-base-300 rounded-box">
                                <table className="table table-zebra w-full">
                                    <thead className="bg-base-200">
                                        <tr>
                                            <th>Bild</th>
                                            <th>Dateiname</th>
                                            <th>Ø Sterne</th>
                                            <th>Kommentare</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {ratingsData.map(r => (
                                            <tr key={r.lr_uuid}>
                                                <td><img src={r.thumb_url} className="w-12 h-12 object-cover rounded shadow-sm" alt={r.filename}/></td>
                                                <td className="font-mono text-sm">{r.filename}</td>
                                                <td className="whitespace-nowrap">{r.avg_rating && r.avg_rating > 0 ? '⭐'.repeat(r.avg_rating) : <span className="opacity-50">-</span>}</td>
                                                <td className="whitespace-pre-wrap text-sm">{r.all_comments || <span className="opacity-50">-</span>}</td>
                                            </tr>
                                        ))}
                                        {ratingsData.length === 0 && <tr><td colSpan={4} className="text-center py-8 opacity-50">Noch keine Bewertungen vorhanden.</td></tr>}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
