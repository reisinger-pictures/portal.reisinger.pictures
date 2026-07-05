import {Photo} from '../../../logic/useGallery';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
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
            showToast('info', t`Bild ist bereits im Warenkorb.`);
            return;
        }
        addToCart({
            photoId: photo.id,
            filename: photo.title || 'Bild ' + photo.id.substring(0, 8),
            thumb_url: photo.thumb_url,
            tier: 'original',
            galleryId: photo.gallery_id,
            price: pricePerItemCents,
        });
        showToast('success', 'In den Warenkorb gelegt');
        onAddToCart();
    };

    const bestPrice = formatMoney(2000);
    const nextTierPlural = nextTierCount === 1 ? '' : 'er';
    return (
        <div className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2">
                <span className="iconify mdi--currency-eur text-primary"></span> <Trans>Preis</Trans>
            </h4>

            {/* Current price display */}
            <div className="flex flex-col items-center py-4">
                <div className="text-4xl font-mono font-bold text-primary">
                    {formatMoney(pricePerItemCents)}
                </div>
                <div className="text-sm opacity-70 mt-1"><Trans>pro Bild</Trans></div>
            </div>

            {/* Volume tiers info */}
            <div className="space-y-2 bg-base-200 p-4 rounded-box border border-base-300">
                <p className="text-sm font-bold opacity-70 uppercase tracking-wide"><Trans>Mengenrabatt Staffel</Trans></p>
                <div className="space-y-1">
                    <div className="flex justify-between text-sm">
                        <span><Trans>1–9 Bilder</Trans></span>
                        <span className="font-mono font-bold">{formatMoney(3000)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="flex items-center gap-1">
                            <Trans>Ab 10 Bilder</Trans>
                            {tier === 2 && (
                                <span className="badge badge-success badge-xs text-xs"><Trans>Aktiv</Trans></span>
                            )}
                        </span>
                        <span className="font-mono font-bold">{formatMoney(2500)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="flex items-center gap-1">
                            <Trans>Ab 20 Bilder</Trans>
                            {tier === 3 && (
                                <span className="badge badge-success badge-xs text-xs"><Trans>Aktiv</Trans></span>
                            )}
                        </span>
                        <span className="font-mono font-bold">{formatMoney(2000)}</span>
                    </div>
                </div>
            </div>

            {/* Next tier hint */}
            {isVolumePricing && nextTierCount > 0 && (
                <div className="text-sm text-center text-primary font-semibold bg-primary/5 p-3 rounded-box border border-primary/20">
                    {t`Noch ${nextTierCount} Bild${nextTierPlural} bis zum nächsten Rabatt:`}<br />
                    <span className="font-bold">{nextTierLabel}</span>
                </div>
            )}

            {isVolumePricing && tier === 3 && (
                <div className="text-sm text-center text-success font-semibold bg-success/5 p-3 rounded-box border border-success/20">
                    <span className="iconify mdi--check-circle inline-block mr-1"></span>
                    <Trans>Bester Rabatt aktiv — {bestPrice} pro Bild</Trans>
                </div>
            )}

            {/* Action button */}
            <button
                onClick={handleAddToCart}
                disabled={isInCart}
                className="btn btn-primary btn-md w-full shadow-sm"
            >
                {isInCart ? (
                    <><span className="iconify mdi--check text-lg"></span> <Trans>Im Warenkorb</Trans></>
                ) : (
                    <><span className="iconify mdi--cart-plus text-lg"></span> <Trans>In den Warenkorb</Trans></>
                )}
            </button>

            {isInCart && (
                <p className="text-xs text-center opacity-60">
                    <Trans>Bereits im Warenkorb — der Preis wird basierend auf der Gesamtanzahl berechnet.</Trans>
                </p>
            )}
        </div>
    );
}
