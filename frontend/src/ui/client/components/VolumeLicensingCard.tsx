import {Photo} from '../../../logic/useGallery';
import {useCart} from '../../../logic/CartContext';
import {useUI} from '../../components/UIContext';
import {formatMoney} from '../../../logic/utils';
import {useVolumeLicensing} from '../../../logic/useVolumeLicensing';

export interface VolumeLicensingCardProps {
    photo: Photo;
    onAddToCart: () => void;
}

export default function VolumeLicensingCard({photo, onAddToCart}: VolumeLicensingCardProps) {
    const {items, addToCart} = useCart();
    const {showToast} = useUI();
    const {pricePerItemCents, tier, nextTierCount, nextTierLabel, isVolumePricing} = useVolumeLicensing(items);

    const isInCart = items.some(i => i.photoId === photo.id);

    const handleAddToCart = () => {
        if (isInCart) {
            showToast('info', 'Bild ist bereits im Warenkorb.');
            return;
        }
        addToCart({
            photoId: photo.id,
            filename: photo.title || 'Bild ' + photo.id.substring(0, 8),
            thumb_url: photo.thumb_url,
            tier: 'original',
            price: pricePerItemCents,
        });
        showToast('success', 'In den Warenkorb gelegt');
        onAddToCart();
    };

    return (
        <div className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2">
                <span className="iconify mdi--currency-eur text-primary"></span> Preis
            </h4>

            {/* Current price display */}
            <div className="flex flex-col items-center py-4">
                <div className="text-4xl font-mono font-bold text-primary">
                    {formatMoney(pricePerItemCents)}
                </div>
                <div className="text-sm opacity-70 mt-1">pro Bild</div>
            </div>

            {/* Volume tiers info */}
            <div className="space-y-2 bg-base-200 p-4 rounded-box border border-base-300">
                <p className="text-sm font-bold opacity-70 uppercase tracking-wide">Mengenrabatt Staffel</p>
                <div className="space-y-1">
                    <div className="flex justify-between text-sm">
                        <span>1–9 Bilder</span>
                        <span className="font-mono font-bold">{formatMoney(3000)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="flex items-center gap-1">
                            Ab 10 Bilder
                            {tier === 2 && (
                                <span className="badge badge-success badge-xs text-[10px]">Aktiv</span>
                            )}
                        </span>
                        <span className="font-mono font-bold">{formatMoney(2500)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="flex items-center gap-1">
                            Ab 20 Bilder
                            {tier === 3 && (
                                <span className="badge badge-success badge-xs text-[10px]">Aktiv</span>
                            )}
                        </span>
                        <span className="font-mono font-bold">{formatMoney(2000)}</span>
                    </div>
                </div>
            </div>

            {/* Next tier hint */}
            {isVolumePricing && nextTierCount > 0 && (
                <div className="text-sm text-center text-primary font-semibold bg-primary/5 p-3 rounded-box border border-primary/20">
                    Noch {nextTierCount} Bild{nextTierCount === 1 ? '' : 'er'} bis zum nächsten Rabatt:<br />
                    <span className="font-bold">{nextTierLabel}</span>
                </div>
            )}

            {isVolumePricing && tier === 3 && (
                <div className="text-sm text-center text-success font-semibold bg-success/5 p-3 rounded-box border border-success/20">
                    <span className="iconify mdi--check-circle inline-block mr-1"></span>
                    Bester Rabatt aktiv — {formatMoney(2000)} pro Bild
                </div>
            )}

            {/* Action button */}
            <button
                onClick={handleAddToCart}
                disabled={isInCart}
                className="btn btn-primary btn-md w-full shadow-sm"
            >
                {isInCart ? (
                    <><span className="iconify mdi--check text-lg"></span> Im Warenkorb</>
                ) : (
                    <><span className="iconify mdi--cart-plus text-lg"></span> In den Warenkorb</>
                )}
            </button>

            {isInCart && (
                <p className="text-xs text-center opacity-60">
                    Bereits im Warenkorb — der Preis wird basierend auf der Gesamtanzahl berechnet.
                </p>
            )}
        </div>
    );
}
