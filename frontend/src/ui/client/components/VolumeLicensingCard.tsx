import {Photo} from '../../../logic/useGallery';
import {t} from "@lingui/core/macro";
import {Trans, Plural} from "@lingui/react/macro";
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
    const {pricePerItemCents, tierIndex, isMaxTier, nextTierCount, nextTierLabel, isVolumePricing, tiers, isLoading} = useVolumeLicensing(items, photo.gallery_id);

    const isInCart = items.some(i => i.photoId === photo.id);

    const handleAddToCart = () => {
        if (isLoading) return;
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
            galleryGroupId: photo.gallery?.gallery_group_id ?? undefined,
            price: pricePerItemCents,
        });
        showToast('success', 'In den Warenkorb gelegt');
        onAddToCart();
    };

    const bestPrice = formatMoney(tiers[tiers.length - 1]?.priceCents ?? 0);
    return (
        <div data-testid="volume-pricing-card" className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2">
                <span className="iconify mdi--currency-eur text-primary"></span> <Trans>Preis</Trans>
            </h4>

            {/* Current price display — hidden until the gallery's descriptor is resolved */}
            <div className="flex flex-col items-center py-4">
                {isLoading ? (
                    <div data-testid="volume-price-loading" className="flex flex-col items-center gap-2 py-2">
                        <span className="loading loading-spinner loading-lg text-primary"></span>
                        <span className="text-sm opacity-70"><Trans>Preis wird geladen…</Trans></span>
                    </div>
                ) : (
                    <>
                        <div className="text-4xl font-mono font-bold text-primary">
                            {formatMoney(pricePerItemCents)}
                        </div>
                        <div className="text-sm opacity-70 mt-1"><Trans>pro Bild</Trans></div>
                    </>
                )}
            </div>

            {/* Volume tiers info */}
            {!isLoading && (
            <div className="space-y-2 bg-base-200 p-4 rounded-box border border-base-300">
                <p className="text-sm font-bold opacity-70 uppercase tracking-wide"><Trans>Mengenrabatt Staffel</Trans></p>
                <div className="space-y-1">
                    {tiers.map((tier, index) => {
                        const nextMin = tiers[index + 1]?.minQuantity;
                        const rangeLabel = tier.minQuantity === 0
                            ? `${tier.minQuantity + 1}–${nextMin ? nextMin - 1 : ''} Bilder`
                            : `Ab ${tier.minQuantity} Bilder`;
                        return (
                            <div key={index} className="flex justify-between text-sm">
                                <span className="flex items-center gap-1">
                                    <span>{rangeLabel}</span>
                                    {tierIndex === index && (
                                        <span className="badge badge-success badge-xs text-xs"><Trans>Aktiv</Trans></span>
                                    )}
                                </span>
                                <span className="font-mono font-bold">{formatMoney(tier.priceCents)}</span>
                            </div>
                        );
                    })}
                </div>
            </div>
            )}

            {/* Next tier hint */}
            {!isLoading && isVolumePricing && nextTierCount > 0 && (
                <div className="text-sm text-center text-primary font-semibold bg-primary/5 p-3 rounded-box border border-primary/20">
                    <Trans><Plural value={nextTierCount} one="Noch # Bild" other="Noch # Bilder" /> bis zum nächsten Rabatt:</Trans><br />
                    <span className="font-bold">{nextTierLabel}</span>
                </div>
            )}

            {!isLoading && isVolumePricing && isMaxTier && (
                <div className="text-sm text-center text-success font-semibold bg-success/5 p-3 rounded-box border border-success/20">
                    <span className="iconify mdi--check-circle inline-block mr-1"></span>
                    <Trans>Bester Rabatt aktiv — {bestPrice} pro Bild</Trans>
                </div>
            )}

            {/* Action button */}
            <button
                onClick={handleAddToCart}
                disabled={isInCart || isLoading}
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
