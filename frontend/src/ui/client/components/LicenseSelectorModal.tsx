import {Photo} from '../../../logic/useGallery';
import {useAuth} from '../../../logic/useAuth';
import {calculateUpgradePrice, DurationTier, isCovered, ResolutionTier, UsageTier} from '../../../logic/pricingLogic';
import {useState} from 'react';
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
        {id: 'web', label: 'Web & Social Media', desc: 'Längste Kante max. 2560px'},
        {id: 'print', label: 'Print (bis A4)', desc: 'Längste Kante max. 4000px'},
        {id: 'original', label: 'Original (Cover)', desc: 'Maximale Originalauflösung'}
    ];

    const handleDownload = (tier: ResolutionTier) => {
        // HIER WIRD SPÄTER DIE API MIT DEM PARAMETER AUFGERUFEN
        // Z.B. window.open('/api/photos/' + photo.id + '/download?tier=' + tier, '_self');
        window.open('/api/photos/' + photo.id + '/download?tier=' + tier, '_self');
        showToast('success', 'Download startet...');
        onClose();
    };

    const handleAddToCart = (tier: ResolutionTier, price: number) => {
        addToCart({
            photoId: photo.id,
            filename: photo.title || 'Bild ' + photo.id.substring(0, 8),
            thumb_url: photo.thumb_url,
            tier,
            price
        });
        showToast('success', 'In den Warenkorb gelegt');
    };

    return (
        <div ref={modalRef} className="modal modal-open z-[70]">
            <div className="modal-box relative max-w-2xl">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--license text-primary"></span> Lizenz wählen
                </h3>
                <p className="opacity-70 mb-6">Wähle die gewünschte Auflösung für das
                    Bild <strong>{photo.title || 'Foto'}</strong>.</p>

                <div className="flex flex-col gap-6 mb-6 bg-base-200 p-5 rounded-box border border-base-300">

                    {/* Nutzungsart */}
                    <div className="form-control w-full">
                        <div className="flex justify-between items-center mb-2">
                            <label className="label-text font-bold">1. Nutzungsart</label>
                            <div className="dropdown dropdown-hover dropdown-end">
                                <label tabIndex={0} className="btn btn-circle btn-ghost btn-xs text-info"><span
                                    className="mdi--information-outline text-lg"></span></label>
                                <div tabIndex={0}
                                     className="dropdown-content z-[20] card card-compact w-72 p-2 shadow-xl bg-base-100 border border-base-300 text-sm">
                                    <div className="card-body">
                                        <p><strong>Redaktionell:</strong><br/>{terms?.editorial}</p>
                                        <div className="divider my-1"></div>
                                        <p><strong>Kommerziell:</strong><br/>{terms?.commercial}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="join w-full shadow-sm">
                            <button
                                className={`btn join-item btn-sm flex-1 ${usage === 'editorial' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setUsage('editorial')}>Redaktionell (x1)
                            </button>
                            <button
                                className={`btn join-item btn-sm flex-1 ${usage === 'commercial' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setUsage('commercial')}>Kommerziell (x3)
                            </button>
                        </div>
                    </div>

                    {/* Nutzungsdauer */}
                    <div className="form-control w-full">
                        <div className="flex justify-between items-center mb-2">
                            <label className="label-text font-bold">2. Nutzungsdauer</label>
                            <div className="dropdown dropdown-hover dropdown-end">
                                <label tabIndex={0} className="btn btn-circle btn-ghost btn-xs text-info"><span
                                    className="mdi--information-outline text-lg"></span></label>
                                <div tabIndex={0}
                                     className="dropdown-content z-[20] card card-compact w-72 p-2 shadow-xl bg-base-100 border border-base-300 text-sm">
                                    <div className="card-body">
                                        <p><strong>1 Jahr:</strong><br/>{terms?.['1_year']}</p>
                                        <div className="divider my-1"></div>
                                        <p><strong>Unbegrenzt:</strong><br/>{terms?.unlimited}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="join w-full shadow-sm">
                            <button
                                className={`btn join-item btn-sm flex-1 ${duration === '1_year' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setDuration('1_year')}>1 Jahr (x1)
                            </button>
                            <button
                                className={`btn join-item btn-sm flex-1 ${duration === 'unlimited' ? 'btn-primary' : 'btn-outline bg-base-100'}`}
                                onClick={() => setDuration('unlimited')}>Unbegrenzt (x2)
                            </button>
                        </div>
                    </div>

                </div>
                <div className="space-y-4">
                    {tiers.map(tier => {
                        const covered = isCovered(user?.flatrate_level, tier.id, usage, duration) || photo?.gallery?.effective_is_free_download;
                        const upgradePrice = calculateUpgradePrice(terms, user?.flatrate_level, tier.id, usage, duration);
                        const canBuy = true; // Stripe-Käufe sind für jeden angemeldeten User erlaubt

                        return (
                            <div key={tier.id}
                                 className={`p-4 rounded-box border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-colors ${covered ? 'border-success bg-success/5' : 'border-base-300 bg-base-100'}`}>
                                <div>
                                    <h4 className="font-bold text-lg flex items-center gap-2">
                                        {tier.label}
                                        {covered &&
                                            <span className="badge badge-success badge-sm text-white">Inklusive</span>}
                                    </h4>
                                    <p className="text-sm opacity-70">{tier.desc}</p>
                                </div>
                                <div className="shrink-0 w-full md:w-auto text-right">
                                    {covered ? (
                                        <button onClick={() => handleDownload(tier.id)}
                                                className="btn btn-success w-full text-white">
                                            <span className="iconify mdi--download"></span> Sofort Download
                                        </button>
                                    ) : canBuy ? (
                                        <button onClick={() => handleAddToCart(tier.id, upgradePrice)}
                                                className="btn btn-primary w-full">
                                            <span
                                                className="iconify mdi--cart-plus"></span> + {upgradePrice.toFixed(2)} €
                                        </button>
                                    ) : (
                                        <button disabled className="btn btn-disabled w-full"
                                                title="Deine Berechtigung erlaubt keine Upgrade-Käufe.">
                                            <span className="iconify mdi--lock"></span> Gesperrt
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
