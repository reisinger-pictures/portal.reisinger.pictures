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
            tier, usage, duration, frequency, price
        });
        showToast('success', 'In den Warenkorb gelegt');
    };

    const handleCustomQuote = () => {
        addToCart({
            photoId: photo.id,
            filename: photo.filename,
            tier: 'original', usage: 'commercial', duration: 'unlimited', frequency: 'mehrmalig',
            price: 0,
            isQuote: true
        });
        showToast('info', 'Angebot zur Liste hinzugefügt');
    };

    const availableTiers = tiers.filter(tier => {
        const covered = hasFullAccess || isCovered(user?.flatrate_level, tier.id, usage, duration, frequency) || photo?.gallery?.effective_is_free_download;
        const canBuy = user?.is_power_user || hasFullAccess;
        return covered || canBuy;
    });

    return (
        <div className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2">
                <span className="iconify mdi--license text-primary"></span> Lizenz wählen
            </h4>
            
            {/* Kompakte 3-Spalten Auswahl */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div className="form-control w-full">
                    <label className="label-text font-bold mb-1.5 text-xs opacity-70">1. Nutzungsart</label>
                    <div className="join w-full shadow-inner border border-base-300 rounded-lg">
                        <button className={`btn join-item btn-xs h-8 flex-1 ${usage === 'editorial' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setUsage('editorial')}>Redaktionell</button>
                        <button className={`btn join-item btn-xs h-8 flex-1 ${usage === 'commercial' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setUsage('commercial')}>Kommerziell</button>
                    </div>
                </div>

                <div className="form-control w-full">
                    <label className="label-text font-bold mb-1.5 text-xs opacity-70">2. Nutzungsdauer</label>
                    <div className="join w-full shadow-inner border border-base-300 rounded-lg">
                        <button className={`btn join-item btn-xs h-8 flex-1 ${duration === '1_year' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setDuration('1_year')}>1 Jahr</button>
                        <button className={`btn join-item btn-xs h-8 flex-1 ${duration === 'unlimited' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setDuration('unlimited')}>Unbegrenzt</button>
                    </div>
                </div>

                <div className="form-control w-full">
                    <label className="label-text font-bold mb-1.5 text-xs opacity-70">3. Häufigkeit</label>
                    <div className="join w-full shadow-inner border border-base-300 rounded-lg">
                        <button className={`btn join-item btn-xs h-8 flex-1 ${frequency === 'einmalig' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setFrequency('einmalig')}>Einmalig</button>
                        <button className={`btn join-item btn-xs h-8 flex-1 ${frequency === 'mehrmalig' ? 'btn-primary' : 'btn-ghost bg-base-100'}`} onClick={() => setFrequency('mehrmalig')}>Mehrmalig</button>
                    </div>
                </div>
            </div>

            {/* Auflösungen */}
            <div className="space-y-2.5 mt-2">
                <label className="label-text font-bold text-sm opacity-80 block mb-1">4. Auflösung & Preis</label>
                
                {availableTiers.map(tier => {
                    const covered = hasFullAccess || isCovered(user?.flatrate_level, tier.id, usage, duration, frequency) || photo?.gallery?.effective_is_free_download;
                    const upgradePrice = calculateUpgradePrice(user?.flatrate_level, tier.id, usage, duration, frequency);
                    const sizeInfo = getSizeInfo(tier.maxEdge);

                    return (
                        <div key={tier.id} className={`p-3.5 rounded-xl border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-colors ${covered ? 'border-success/60 bg-base-100' : 'border-base-300 bg-base-100 hover:border-primary/50'}`}>
                            <div className="flex-1">
                                <div className="font-extrabold text-base flex items-center gap-2.5">
                                    {tier.label}
                                    {covered && <span className="badge badge-success badge-sm text-white font-bold px-3 py-2">Inklusive</span>}
                                </div>
                                {sizeInfo && <div className="text-xs opacity-70 font-mono mt-1">{sizeInfo}</div>}
                            </div>
                            <div className="shrink-0 w-full md:w-auto">
                                {covered ? (
                                    <a 
                                        href={`/api/photos/${photo.id}/download?tier=${photo?.gallery?.effective_is_free_download ? 'original' : tier.id}`}
                                        target="_blank" rel="noopener noreferrer"
                                        className="btn btn-success btn-md text-white w-full font-bold shadow-sm"
                                    >
                                        <span className="iconify mdi--download text-lg"></span> Jetzt herunterladen
                                    </a>
                                ) : (
                                    <button onClick={() => handleAddToCart(tier.id, upgradePrice)} className="btn btn-primary btn-md w-full font-bold shadow-sm">
                                        <span className="iconify mdi--cart-plus text-lg"></span> 
                                        <span className="text-lg tabular-nums">{upgradePrice.toFixed(2)} €</span>
                                    </button>
                                )}
                            </div>
                        </div>
                    );
                })}

                {availableTiers.length === 0 && (
                    <div className="p-5 text-center text-sm opacity-70 bg-base-200 rounded-xl border border-base-300 italic">
                        Diese Nutzungskombination ist mit deinem aktuellen Tarif nicht verfügbar.
                    </div>
                )}
            </div>

            {/* Custom Quote */}
            {photo.gallery?.allow_custom_quotes && (
                <div className="mt-2 pt-4 border-t border-base-300 text-center">
                    <p className="text-xs opacity-60 mb-2.5">Besondere Anforderungen? (Exklusivität, Weltweite Rechte)</p>
                    <button onClick={handleCustomQuote} className="btn btn-outline btn-sm w-full">
                        <span className="iconify mdi--file-document-edit-outline"></span> Individuelles Angebot anfragen
                    </button>
                </div>
            )}
        </div>
    );
}
