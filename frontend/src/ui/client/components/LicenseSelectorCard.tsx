import { useState } from 'react';
import { Photo } from '../../../logic/useGallery';
import { useAuth } from '../../../logic/useAuth';
import { usePricing, ResolutionTier, UsageTier, DurationTier, FrequencyTier } from '../../../logic/usePricing';
import { useLicenseTerms } from '../../../logic/useLicenseTerms';
import { useCart } from '../../../logic/CartContext';
import { useUI } from '../../components/UIContext';

export default function LicenseSelectorCard({ photo }: { photo: Photo }) {
    const [usage, setUsage] = useState<UsageTier>('editorial');
    const [duration, setDuration] = useState<DurationTier>('1_year');
    const [frequency, setFrequency] = useState<FrequencyTier>('einmalig');
    const [quoteNote, setQuoteNote] = useState('');
    
    const { terms } = useLicenseTerms();
    const { user } = useAuth();
    const { showToast } = useUI();
    const { isCovered, calculateUpgradePrice } = usePricing(parseFloat(terms?.base_price || '35.00'));
    const { addToCart } = useCart();

    const hasFullAccess = user?.is_admin || user?.is_photographer;

    const tiers: { id: ResolutionTier, label: string, maxEdge: number | null }[] = [
        { id: 'web', label: 'Web & Social Media', maxEdge: 2560 },
        { id: 'print', label: 'Print (bis A4)', maxEdge: 4000 },
        { id: 'original', label: 'Original', maxEdge: null }
    ];

    const getSizeInfo = (maxEdge: number | null) => {
        if (!photo?.width || !photo?.height) return null;
        const isLandscape = photo.width > photo.height;
        const longest = isLandscape ? photo.width : photo.height;
        
        let w = photo.width;
        let h = photo.height;

        if (maxEdge && longest > maxEdge) {
            const ratio = maxEdge / longest;
            w = Math.round(w * ratio);
            h = Math.round(h * ratio);
        }

        const mp = ((w * h) / 1000000).toFixed(1);
        return `${w} x ${h}px • ${mp} MP`;
    };

    const handleAddToCart = (tier: ResolutionTier, price: number) => {
        addToCart({
            photoId: photo.id,
            filename: photo.filename,
            thumb_url: photo.thumb_url,
            tier, usage, duration, frequency, price
        });
        showToast('success', 'In den Warenkorb gelegt');
    };

    const handleCustomQuote = () => {
        addToCart({
            photoId: photo.id,
            filename: photo.filename,
            thumb_url: photo.thumb_url,
            tier: 'original', usage: 'commercial', duration: 'unlimited', frequency: 'mehrmalig',
            price: 0,
            isQuote: true,
            notes: quoteNote
        });
        showToast('info', 'Angebot zum Warenkorb hinzugefügt');
        setQuoteNote('');
    };

    const availableTiers = tiers.filter(tier => {
        const covered = hasFullAccess || isCovered(user?.flatrate_level, tier.id, usage, duration, frequency) || photo?.gallery?.effective_is_free_download;
        return covered || true; // JEDER DARF KAUFEN
    });

    return (
        <div className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2">
                <span className="iconify mdi--license text-primary"></span> Lizenz wählen
            </h4>
            
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <div className="form-control w-full">
                    <label className="label-text font-bold mb-2 text-xs opacity-70 uppercase tracking-wide">1. Nutzungsart</label>
                    <div className="flex flex-wrap gap-2">
                        <button className={`btn btn-sm shrink-0 ${usage === 'editorial' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setUsage('editorial')}>Redaktionell</button>
                        <button className={`btn btn-sm shrink-0 ${usage === 'commercial' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setUsage('commercial')}>Kommerziell</button>
                    </div>
                </div>

                <div className="form-control w-full">
                    <label className="label-text font-bold mb-2 text-xs opacity-70 uppercase tracking-wide">2. Nutzungsdauer</label>
                    <div className="flex flex-wrap gap-2">
                        <button className={`btn btn-sm shrink-0 ${duration === '1_year' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setDuration('1_year')}>1 Jahr</button>
                        <button className={`btn btn-sm shrink-0 ${duration === 'unlimited' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setDuration('unlimited')}>Unbegrenzt</button>
                    </div>
                </div>

                <div className="form-control w-full">
                    <label className="label-text font-bold mb-2 text-xs opacity-70 uppercase tracking-wide">3. Häufigkeit</label>
                    <div className="flex flex-wrap gap-2">
                        <button className={`btn btn-sm shrink-0 ${frequency === 'einmalig' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setFrequency('einmalig')}>Einmalig</button>
                        <button className={`btn btn-sm shrink-0 ${frequency === 'mehrmalig' ? 'btn-primary' : 'btn-outline bg-base-100 border-base-300'}`} onClick={() => setFrequency('mehrmalig')}>Mehrmalig</button>
                    </div>
                </div>
            </div>

            <div className="space-y-3 mt-4 pt-4 border-t border-base-300">
                <label className="label-text font-bold text-xs opacity-70 block mb-2 uppercase tracking-wide">4. Auflösung & Preis</label>
                
                {availableTiers.map(tier => {
                    const covered = hasFullAccess || isCovered(user?.flatrate_level, tier.id, usage, duration, frequency) || photo?.gallery?.effective_is_free_download;
                    const upgradePrice = calculateUpgradePrice(user?.flatrate_level, tier.id, usage, duration, frequency);
                    const sizeInfo = getSizeInfo(tier.maxEdge);

                    return (
                        <div key={tier.id} className={`p-4 rounded-xl border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-colors ${covered ? 'border-success/60 bg-success/5' : 'border-base-300 bg-base-200/50 hover:bg-base-200'}`}>
                            <div className="flex-1">
                                <div className="font-bold text-base md:text-lg flex flex-wrap items-center gap-2">
                                    {tier.label}
                                    {covered && <span className="badge badge-success badge-sm text-white font-bold">Inklusive</span>}
                                </div>
                                {sizeInfo && <div className="text-xs opacity-70 font-mono mt-1">{sizeInfo}</div>}
                            </div>
                            <div className="shrink-0 w-full md:w-auto">
                                {covered ? (
                                    <a 
                                        href={`/api/photos/${photo.id}/download?tier=${photo?.gallery?.effective_is_free_download ? 'original' : tier.id}`}
                                        target="_blank" rel="noopener noreferrer"
                                        className="btn btn-success btn-md text-white w-full shadow-sm"
                                    >
                                        <span className="iconify mdi--download text-lg"></span> Download
                                    </a>
                                ) : (
                                    <button onClick={() => handleAddToCart(tier.id, upgradePrice)} className="btn btn-primary btn-md w-full shadow-sm">
                                        <span className="iconify mdi--cart-plus text-lg"></span> 
                                        <span className="font-bold tabular-nums">{upgradePrice.toFixed(2)} €</span>
                                    </button>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="mt-4 pt-4 border-t border-base-300">
                <p className="text-xs font-bold opacity-70 mb-2 uppercase tracking-wide">Sonderanfrage (Angebot)</p>
                <textarea 
                    className="textarea textarea-bordered w-full h-16 text-sm resize-none mb-3" 
                    placeholder="Besondere Anforderungen (z.B. Exklusivität, Weltweite Rechte)..."
                    value={quoteNote}
                    onChange={(e) => setQuoteNote(e.target.value)}
                ></textarea>
                <button onClick={handleCustomQuote} className="btn btn-outline btn-sm w-full">
                    <span className="iconify mdi--cart-plus"></span> Als Angebot in den Warenkorb
                </button>
            </div>
        </div>
    );
}