import {useState} from 'react';
import {Photo} from '../../../logic/useGallery';
import {useAuth} from '../../../logic/useAuth';
import {useCart} from '../../../logic/CartContext';
import {useUI} from '../../components/UIContext';
import {useLicenseCatalog} from '../../../logic/useLicenseCatalog';
import {formatMoney} from '../../../logic/utils';
import {ResolutionTier} from '../../../logic/usePricing';

export interface LicenseSelectorCardProps {
    photo: Photo;
}

export default function LicenseSelectorCard({photo}: LicenseSelectorCardProps) {
    const {catalog, isLoading} = useLicenseCatalog();
    const {user} = useAuth();
    const {showToast} = useUI();
    const {addToCart} = useCart();

    const [selectedUseCaseId, setSelectedUseCaseId] = useState<string>('');
    const [selectedModifiers, setSelectedModifiers] = useState<string[]>([]);
    const [quoteNote, setQuoteNote] = useState('');

    if (isLoading || !catalog) return <span className="loading loading-spinner m-6"></span>;

    const actualSelectedUseCaseId = selectedUseCaseId || (catalog.use_cases.length > 0 ? catalog.use_cases[0].id : '');
    const selectedUseCase = catalog.use_cases.find(u => u.id === actualSelectedUseCaseId) || catalog.use_cases[0];

    // Berechtigungs-Logik
    const canBuy = true; // Stripe-Käufe sind für jeden angemeldeten User erlaubt
    const hasFullAccess = user?.is_admin || user?.is_photographer;

    const RES_RANKS: Record<string, number> = {'none': 0, 'web': 1, 'print': 2, 'original': 3};
    const userRank = RES_RANKS[user?.flatrate_level || 'none'] || 0;
    const reqRank = RES_RANKS[selectedUseCase?.flatrate_tier || 'web'] || 0;

    const basePrice = selectedUseCase ? Number(selectedUseCase.base_price) : 0;
    const isBaseCovered = userRank >= reqRank || photo?.gallery?.effective_is_free_download || hasFullAccess;
    const coveredBasePrice = isBaseCovered ? 0 : basePrice;

    let surchargeAmount = 0;
    const activeModifiers = catalog.modifiers.filter(m => selectedModifiers.includes(m.id));
    activeModifiers.forEach(m => {
        // Flatrate erlässt den Zuschlag, WENN die Kategorie abgedeckt ist UND der Zuschlag als inklusiv markiert ist
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
                    {catalog.use_cases.map(uc => {
                        const ucReqRank = RES_RANKS[uc.flatrate_tier || 'web'] || 0;
                        const ucCovered = userRank >= ucReqRank || photo?.gallery?.effective_is_free_download || hasFullAccess;

                        // Normale Clients dürfen nur sehen was sie laden dürfen
                        if (!canBuy && !ucCovered) return null;

                        return (
                            <label key={uc.id}
                                   className={`cursor-pointer p-3 rounded-box border flex items-center gap-3 transition-colors ${actualSelectedUseCaseId === uc.id ? 'border-primary bg-primary/5' : 'border-base-300 bg-base-200/50 hover:bg-base-200'}`}>
                                <input type="radio" className="radio-primary radio"
                                       checked={actualSelectedUseCaseId === uc.id} onChange={() => {
                                    setSelectedUseCaseId(uc.id);
                                    setSelectedModifiers([]);
                                }}/>
                                <div className="flex-1">
                                    <div className="font-bold text-sm">{uc.name}</div>
                                    <div className="text-sm opacity-70">{uc.description}</div>
                                </div>
                                <div className="font-mono font-bold text-sm shrink-0">
                                    {ucCovered ? <span
                                        className="text-success text-sm">Inklusive</span> : formatMoney(Number(uc.base_price))}
                                </div>
                            </label>
                        );
                    })}
                </div>
            </div>

            {/* Modifiers nur anzeigen, wenn es welche gibt UND der User upselling machen darf, ODER es inkludierte gibt */}
            {catalog.modifiers.length > 0 && (
                <div className="space-y-2 pt-2">
                    <label className="label-text font-bold text-sm opacity-70 uppercase tracking-wide">2. Optionale
                        Zuschläge</label>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {catalog.modifiers.map(mod => {
                            const isModCovered = isBaseCovered && mod.is_included_in_flatrate;
                            // Normale Clients dürfen keine aufpreispflichtigen Optionen wählen
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
                    {finalPrice === 0 ? (
                        <a href={`/api/photos/${photo.id}/download?tier=${photo?.gallery?.effective_is_free_download ? 'original' : selectedUseCase?.flatrate_tier}`}
                           target="_blank" rel="noopener noreferrer"
                           className="btn btn-success btn-md text-white w-full shadow-sm"><span
                            className="iconify mdi--download text-lg"></span> Download</a>
                    ) : (
                        <button onClick={handleAddToCart} disabled={!canBuy}
                                className="btn btn-primary btn-md w-full shadow-sm"
                                title={!canBuy ?"Bitte Angebot anfragen" :""}>
                            <span className="iconify mdi--cart-plus text-lg"></span> In den Warenkorb
                        </button>
                    )}
                </div>
            </div>

            <div className="mt-2 pt-4 border-t border-base-300">
                <p className="text-sm font-bold opacity-70 mb-2 uppercase tracking-wide">Sonderanfrage (Angebot)</p>
                <textarea className="textarea textarea-bordered w-full h-16 text-sm resize-none mb-2"
                          placeholder="Z.B. Exklusivrecht erforderlich..." value={quoteNote}
                          onChange={(e) => setQuoteNote(e.target.value)}></textarea>
                <button onClick={handleCustomQuote} className="btn btn-outline btn-sm w-full">
                    <span className="iconify mdi--file-document-edit-outline"></span> Als Angebot anfragen
                </button>
            </div>
        </div>
    );
}
