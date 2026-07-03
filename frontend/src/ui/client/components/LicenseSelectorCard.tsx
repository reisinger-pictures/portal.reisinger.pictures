import {useState} from 'react';
import {Photo} from '../../../logic/useGallery';
import {useAuth} from '../../../logic/useAuth';
import {usePermissions} from '../../../logic/usePermissions';
import {UserRole} from '../../../logic/useUsers';
import {useCart} from '../../../logic/CartContext';
import {useUI} from '../../components/UIContext';
import {useLicenseCatalog} from '../../../logic/useLicenseCatalog';
import {useSearchParams} from 'react-router-dom';
import {formatMoney} from '../../../logic/utils';
import {ResolutionTier} from '../../../logic/pricingLogic';

export interface LicenseSelectorCardProps {
    photo: Photo;
}

export default function LicenseSelectorCard({photo}: LicenseSelectorCardProps) {
    const {catalog, isLoading} = useLicenseCatalog();
    const {user} = useAuth();
    const {isStaff} = usePermissions();
    const {showToast} = useUI();
    const {addToCart} = useCart();
    const [searchParams] = useSearchParams();

    const [selectedUseCaseId, setSelectedUseCaseId] = useState<string>('');
    const [selectedModifiers, setSelectedModifiers] = useState<string[]>([]);
    const [quoteNote, setQuoteNote] = useState('');

    if (isLoading || !catalog) return <span className="loading loading-spinner m-6"></span>;

    const isPhotoEditorialOnly = photo?.effective_is_editorial_only || photo?.is_editorial_only;
    const isClientView = searchParams.get('view') === 'client';
    const hasFullAccess = isStaff && !isClientView;

    let canBuy = true;
    if (user) {
        const isNormalClient = user.roles?.includes(UserRole.CLIENT) && !user.roles?.includes(UserRole.POWER_USER) && !isStaff;
        if (isNormalClient) canBuy = false;
        if (isClientView && !user.roles?.includes(UserRole.POWER_USER)) canBuy = false;
    }

    const RES_RANKS: Record<string, number> = {'none': 0, 'web': 1, 'print': 2, 'original': 3};
    const userRank = RES_RANKS[user?.flatrate_level || 'none'] || 0;

    const displayedUseCases = catalog.use_cases.filter(uc => {
        const isCommercialBlocked = isPhotoEditorialOnly && /werbung|kampagne|kommerziell/i.test(uc.name + ' ' + (uc.description || ''));
        if (isCommercialBlocked) return false;
        
        const ucReqRank = RES_RANKS[uc.flatrate_tier || 'web'] || 0;
        const ucCovered = userRank >= ucReqRank || photo?.gallery?.effective_is_free_download;
        if (!canBuy && !ucCovered) return false;

        return true;
    });

    displayedUseCases.sort((a, b) => {
        const aReqRank = RES_RANKS[a.flatrate_tier || 'web'] || 0;
        const aCovered = userRank >= aReqRank || photo?.gallery?.effective_is_free_download;
        const aPrice = aCovered ? 0 : Number(a.base_price);

        const bReqRank = RES_RANKS[b.flatrate_tier || 'web'] || 0;
        const bCovered = userRank >= bReqRank || photo?.gallery?.effective_is_free_download;
        const bPrice = bCovered ? 0 : Number(b.base_price);

        if (aPrice !== bPrice) {
            return aPrice - bPrice;
        }
        return Number(a.base_price) - Number(b.base_price);
    });

    const actualSelectedUseCaseId = selectedUseCaseId || (displayedUseCases.length > 0 ? displayedUseCases[0].id : '');
    const selectedUseCase = catalog.use_cases.find(u => u.id === actualSelectedUseCaseId) || catalog.use_cases[0];

    const reqRank = RES_RANKS[selectedUseCase?.flatrate_tier || 'web'] || 0;

    const basePrice = selectedUseCase ? Number(selectedUseCase.base_price) : 0;
    const isBaseCovered = userRank >= reqRank || photo?.gallery?.effective_is_free_download;
    const coveredBasePrice = isBaseCovered ? 0 : basePrice;

    let surchargeAmount = 0;
    const activeModifiers = catalog.modifiers.filter(m => selectedModifiers.includes(m.id));
    activeModifiers.forEach(m => {
        if (isBaseCovered && m.is_included_in_flatrate) return;
        surchargeAmount += Math.round(basePrice * (Number(m.percent_surcharge) / 100));
    });

    const finalPrice = coveredBasePrice + surchargeAmount;

    const handleAddToCart = () => {
        if (!selectedUseCase) return;
        addToCart({
            photoId: photo.id,
            filename: photo.title || 'Bild ' + photo.id.substring(0, 8),
            thumb_url: photo.thumb_url,
            tier: selectedUseCase.flatrate_tier as ResolutionTier,
            galleryId: photo.gallery_id,
            useCaseId: selectedUseCase.id,
            useCaseName: selectedUseCase.name,
            modifierIds: selectedModifiers,
            modifierNames: activeModifiers.map(m => m.name),
            price: finalPrice
        });
        showToast('success', 'In den Warenkorb gelegt');
    };

    const handleCustomQuote = () => {
        addToCart({
            photoId: photo.id, filename: photo.title || 'Bild ' + photo.id.substring(0, 8), thumb_url: photo.thumb_url,
            tier: 'original' as ResolutionTier, price: 0, isQuote: true, notes: quoteNote
        });
        showToast('info', 'Angebot zum Warenkorb hinzugefügt');
        setQuoteNote('');
    };

    return (
        <div className="bg-base-100 p-5 md:p-6 rounded-box border border-base-300 shadow-sm flex flex-col gap-5">
            <h4 className="font-bold text-xl flex items-center gap-2"><span
                className="iconify mdi--license text-primary"></span> Lizenz wählen</h4>

            <div className="space-y-2">
                <label className="label-text font-bold text-sm opacity-70 uppercase tracking-wide">1. Typ /
                    Grundhonorar</label>
                <div className="flex flex-col gap-2">
                    {displayedUseCases.map(uc => {
                        const ucReqRank = RES_RANKS[uc.flatrate_tier || 'web'] || 0;
                        const ucCovered = userRank >= ucReqRank || photo?.gallery?.effective_is_free_download;

                        return (
                            <label key={uc.id}
                                   className={`p-3 rounded-box border flex items-center gap-3 transition-colors ${actualSelectedUseCaseId === uc.id ? 'border-primary bg-primary/5' : 'border-base-300 bg-base-200/50 hover:bg-base-200'} cursor-pointer`}>
                                <input type="radio" className="radio-primary radio"
                                       checked={actualSelectedUseCaseId === uc.id}
                                       onChange={() => {
                                           setSelectedUseCaseId(uc.id);
                                           setSelectedModifiers([]);
                                       }}/>
                                <div className="flex-1">
                                    <div className="font-bold text-sm flex flex-wrap items-center gap-2">
                                        {uc.name}
                                    </div>
                                    <div className="text-sm opacity-70">{uc.description}</div>
                                </div>
                                <div className="font-mono font-bold text-sm shrink-0">
                                    {ucCovered ? <span className="text-success text-sm">Inklusive</span> : formatMoney(Number(uc.base_price))}
                                </div>
                            </label>
                        );
                    })}
                </div>
            </div>

            {catalog.modifiers.length > 0 && (
                <div className="space-y-2 pt-2">
                    <label className="label-text font-bold text-sm opacity-70 uppercase tracking-wide">2. Optionale
                        Zuschläge</label>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {catalog.modifiers.map(mod => {
                            const isModCovered = isBaseCovered && mod.is_included_in_flatrate;
                            if (!canBuy && !isModCovered) return null;

                            const isChecked = selectedModifiers.includes(mod.id);
                            const currentSurcharge = basePrice * (Number(mod.percent_surcharge) / 100);

                            return (
                                <label key={mod.id}
                                       className={`cursor-pointer p-3 rounded-box border flex items-start gap-3 transition-colors ${isChecked ? 'border-warning bg-warning/5' : 'border-base-300 bg-base-100 hover:bg-base-200'}`}>
                                    <input type="checkbox" className="checkbox-warning checkbox mt-0.5"
                                           checked={isChecked} onChange={(e) => {
                                        if (e.target.checked) setSelectedModifiers([...selectedModifiers, mod.id]);
                                        else setSelectedModifiers(selectedModifiers.filter(id => id !== mod.id));
                                    }}/>
                                    <div className="flex-1">
                                        <div className="font-bold text-sm">{mod.name}</div>
                                        <div className="text-sm opacity-70 mb-1">{mod.description}</div>
                                        <div className="font-mono text-sm font-bold text-warning">
                                            {isModCovered ? <span
                                                className="text-success text-sm">Kostenfrei (Flatrate)</span> : `+${formatMoney(currentSurcharge)} (+${Number(mod.percent_surcharge)}%)`}
                                        </div>
                                    </div>
                                </label>
                            );
                        })}
                    </div>
                </div>
            )}

            <div
                className="mt-2 pt-4 border-t border-base-300 flex flex-col md:flex-row items-center justify-between gap-4">
                <div>
                    <div className="text-2xl font-mono font-bold text-primary">{formatMoney(finalPrice)}</div>
                    {isBaseCovered &&
                        <div className="text-sm text-success font-bold mt-1">Grundhonorar durch Flatrate gedeckt</div>}
                </div>
                <div className="w-full md:w-auto flex flex-col gap-2">
                    {/* Primärer Aktions-Button */}
                    {finalPrice === 0 ? (
                        <a href={`/api/photos/${photo.id}/download?tier=${photo?.gallery?.effective_is_free_download ? 'original' : selectedUseCase?.flatrate_tier}`}
                           target="_blank" className="btn btn-success btn-md text-white w-full shadow-sm"><span
                            className="iconify mdi--download text-lg"></span> Download</a>
                    ) : canBuy ? (
                        <button onClick={handleAddToCart}
                                className="btn btn-primary btn-md w-full shadow-sm"><span
                            className="iconify mdi--cart-plus text-lg"></span> In den Warenkorb
                        </button>
                    ) : (
                        <button onClick={handleCustomQuote} className="btn btn-outline btn-primary w-full shadow-sm"
                                title="Erweiterte Rechte als Angebot beim Fotografen anfragen">
                            <span className="iconify mdi--email-fast text-lg"></span> Upgrade anfragen
                        </button>
                    )}

                    {/* Admin/Fotografen Override - Immer sichtbar falls berechtigt */}
                    {hasFullAccess && (
                        <a href={`/api/photos/${photo.id}/download?tier=original`}
                           target="_blank"
                           className="btn btn-outline btn-neutral btn-sm w-full shadow-sm mt-1">
                            <span className="iconify mdi--shield-check-outline text-lg"></span> Admin Download
                        </a>
                    )}
                </div>
            </div>

            {canBuy && (
                <div className="mt-2 pt-4 border-t border-base-300">
                    <p className="text-sm font-bold opacity-70 mb-2 uppercase tracking-wide">Sonderanfrage (Angebot)</p>
                    <textarea className="textarea textarea-bordered w-full h-16 text-sm resize-none mb-2"
                              placeholder="Z.B. Exklusivrecht erforderlich..." value={quoteNote}
                              onChange={(e) => setQuoteNote(e.target.value)}></textarea>
                    <button onClick={handleCustomQuote} className="btn btn-outline btn-sm w-full"><span
                        className="iconify mdi--file-document-edit-outline"></span> Als Angebot anfragen
                    </button>
                </div>
            )}
        </div>
    );
}
