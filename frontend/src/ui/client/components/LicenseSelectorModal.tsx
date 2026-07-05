import {Photo} from '../../../logic/useGallery';
import {useAuth} from '../../../logic/useAuth';
import {calculateUpgradePrice, DurationTier, isCovered, ResolutionTier, UsageTier} from '../../../logic/pricingLogic';
import {useState} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useCart} from '../../../logic/CartContext';
import {useUI} from '../../components/UIContext';
import {useFocusTrap} from '../../../logic/useFocusTrap';

interface LicenseSelectorModalProps {
    photo: Photo | null;
    onClose: () => void;
}

interface TierOption {
    id: ResolutionTier;
    label: string;
    desc: string;
}

export default function LicenseSelectorModal({photo, onClose}: LicenseSelectorModalProps) {
    const modalRef = useFocusTrap(!!photo);
    const [usage, setUsage] = useState<UsageTier>('editorial');
    const [duration, setDuration] = useState<DurationTier>('1_year');
    const {terms} = useLicenseTerms();
    const {user} = useAuth();
    const {showToast} = useUI();
    const {addToCart} = useCart(); // Dynamischer Preis

    if (!photo) return null;

    const tiers: TierOption[] = [
        {id: 'web', label: t`Web & Social Media`, desc: t`Längste Kante max. 2560px`},
        {id: 'print', label: t`Print (bis A4)`, desc: t`Längste Kante max. 4000px`},
        {id: 'original', label: t`Original (Cover)`, desc: t`Maximale Originalauflösung`}
    ];

    const handleDownload = (tier: ResolutionTier) => {
        // HIER WIRD SPÄTER DIE API MIT DEM PARAMETER AUFGERUFEN
        // Z.B. window.open('/api/photos/' + photo.id + '/download?tier=' + tier, '_self');
        window.open('/api/photos/' + photo.id + '/download?tier=' + tier, '_self');
        showToast('success', t`Download startet...`);
        onClose();
    };

    const handleAddToCart = (tier: ResolutionTier, price: number) => {
        addToCart({
            photoId: photo.id,
            filename: photo.title || 'Bild ' + photo.id.substring(0, 8),
            thumb_url: photo.thumb_url,
            tier,
            galleryId: photo.gallery_id,
            price
        });
        showToast('success', t`In den Warenkorb gelegt`);
    };

    const photoTitle = photo.title || t`Foto`;
    return (
        <div ref={modalRef} className="modal modal-open">
            <div className="modal-box relative max-w-2xl">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--license text-primary"></span> <Trans>Lizenz wählen</Trans>
                </h3>
                <p className="opacity-70 mb-6">                    <Trans>Wähle die gewünschte Auflösung für das
                    Bild <strong>{photoTitle}</strong>.</Trans></p>

                <div className="flex flex-col gap-6 mb-6 bg-base-200 p-5 rounded-box border border-base-300">

                    {/* Nutzungsart */}
                    <div className="form-control w-full">
                        <div className="flex justify-between items-center mb-2">
                            <label className="label-text font-bold"><Trans>1. Nutzungsart</Trans></label>
                            <div className="dropdown dropdown-hover dropdown-end">
                                <label tabIndex={0} className="btn btn-circle btn-ghost btn-xs text-info"><span
                                    className="mdi--information-outline text-lg"></span></label>
                                <div tabIndex={0}
                                     className="dropdown-content z-30 card card-compact w-72 p-2 shadow-xl bg-base-100 border border-base-300 text-sm">
                                    <div className="card-body">
                                        <p><strong><Trans>Redaktionell:</Trans></strong><br/>{terms?.editorial}</p>
                                        <div className="divider my-1"></div>
                                        <p><strong><Trans>Kommerziell:</Trans></strong><br/>{terms?.commercial}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="join w-full shadow-sm">
                            <button
                                className={`btn join-item btn-sm flex-1 ${usage === 'editorial' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setUsage('editorial')}><Trans>Redaktionell (x1)</Trans>
                            </button>
                            <button
                                className={`btn join-item btn-sm flex-1 ${usage === 'commercial' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setUsage('commercial')}><Trans>Kommerziell (x3)</Trans>
                            </button>
                        </div>
                    </div>

                    {/* Nutzungsdauer */}
                    <div className="form-control w-full">
                        <div className="flex justify-between items-center mb-2">
                            <label className="label-text font-bold"><Trans>2. Nutzungsdauer</Trans></label>
                            <div className="dropdown dropdown-hover dropdown-end">
                                <label tabIndex={0} className="btn btn-circle btn-ghost btn-xs text-info"><span
                                    className="mdi--information-outline text-lg"></span></label>
                                <div tabIndex={0}
                                     className="dropdown-content z-30 card card-compact w-72 p-2 shadow-xl bg-base-100 border border-base-300 text-sm">
                                    <div className="card-body">
                                        <p><strong><Trans>1 Jahr:</Trans></strong><br/>{terms?.['1_year']}</p>
                                        <div className="divider my-1"></div>
                                        <p><strong><Trans>Unbegrenzt:</Trans></strong><br/>{terms?.unlimited}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="join w-full shadow-sm">
                            <button
                                className={`btn join-item btn-sm flex-1 ${duration === '1_year' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setDuration('1_year')}><Trans>1 Jahr (x1)</Trans>
                            </button>
                            <button
                                className={`btn join-item btn-sm flex-1 ${duration === 'unlimited' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setDuration('unlimited')}><Trans>Unbegrenzt (x2)</Trans>
                            </button>
                        </div>
                    </div>

                </div>
                <div className="space-y-4">
                    {tiers.map(tier => {
                        const covered = isCovered(user?.flatrate_level, tier.id, usage, duration) || photo?.gallery?.effective_is_free_download;
                        const upgradePrice = calculateUpgradePrice(terms, user?.flatrate_level, tier.id, usage, duration);
                        const upgradePriceFormatted = upgradePrice.toFixed(2);
                        const canBuy = true; // Stripe-Käufe sind für jeden angemeldeten User erlaubt

                        return (
                            <div key={tier.id}
                                 className={`p-4 rounded-box border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-colors ${covered ? 'border-success bg-success/5' : 'border-base-300 bg-base-100'}`}>
                                <div>
                                    <h4 className="font-bold text-lg flex items-center gap-2">
                                        {tier.label}
                                        {covered &&
                                            <span className="badge badge-success badge-sm text-white"><Trans>Inklusive</Trans></span>}
                                    </h4>
                                    <p className="text-sm opacity-70">{tier.desc}</p>
                                </div>
                                <div className="shrink-0 w-full md:w-auto text-right">
                                    {covered ? (
                                        <button onClick={() => handleDownload(tier.id)}
                                                className="btn btn-success w-full text-white">
                                            <span className="iconify mdi--download"></span> <Trans>Sofort Download</Trans>
                                        </button>
                                    ) : canBuy ? (
                                        <button onClick={() => handleAddToCart(tier.id, upgradePrice)}
                                                className="btn btn-primary w-full">
                                            <span
                                                className="iconify mdi--cart-plus"></span> <Trans>+ {upgradePriceFormatted} €</Trans>
                                        </button>
                                    ) : (
                                        <button disabled className="btn btn-disabled w-full"
                                                title={t`Deine Berechtigung erlaubt keine Upgrade-Käufe.`}>
                                            <span className="iconify mdi--lock"></span> <Trans>Gesperrt</Trans>
                                        </button>
                                    )}
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
