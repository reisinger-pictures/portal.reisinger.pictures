import {useState} from 'react';
import {InvoiceDiscount, InvoiceItem} from '../../../api';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {calculateB2CFlexPrice, calculateShootingPrice, ShootingDiscount} from '../../../logic/shootingCalculator';
import {useBrand} from '../../../logic/useBrand';

interface ShootingCalculatorModalProps {
    isOpen: boolean;
    onClose: () => void;
    onAddPackage: (item: InvoiceItem, discount: InvoiceDiscount | null) => void;
}

export default function ShootingCalculatorModal({isOpen, onClose, onAddPackage}: ShootingCalculatorModalProps) {
    const {isSrp} = useBrand();
    const {terms} = useLicenseTerms();

    const [usePremium, setUsePremium] = useState(false);
    // calcMode is derived from the current brand (via useBrand() / hostname), not from a
    // brand-ID, so it stays in sync with the domain automatically.
    const calcMode = (isSrp && !usePremium) ? 'srp' : 'rp';

    // --- RP (B2B) State ---
    const [calcDuration, setCalcDuration] = useState<number>(90);
    const [calcImages, setCalcImages] = useState<number>(15);
    const [calcIsFlatrate, setCalcIsFlatrate] = useState<boolean>(false);
    const [calcIsOutdoor, setCalcIsOutdoor] = useState<boolean>(false);
    const [calcDiscount, setCalcDiscount] = useState<ShootingDiscount>('0');

    // --- SRP (B2C) State ---
    const [srpType, setSrpType] = useState<'portrait' | 'couple' | 'nude'>('portrait');
    const [srpSetup, setSrpSetup] = useState<'outdoor' | 'outdoor_flash' | 'indoor'>('outdoor');
    const [srpExtra, setSrpExtra] = useState<number>(0);
    const [srpPrivate, setSrpPrivate] = useState<boolean>(false);

    if (!isOpen) return null;

    let packagePriceEuro = 0;
    let finalPriceEuro = 0;
    let discountAbsolute = 0;

    if (calcMode === 'srp') {
        const res = calculateB2CFlexPrice({
            type: srpType,
            setup: srpSetup,
            extraImages: srpExtra,
            isFullyPrivate: srpPrivate
        });
        packagePriceEuro = res.packagePrice;
        finalPriceEuro = res.finalPrice;
        discountAbsolute = res.discountAbsolute;
    } else {
        const res = calculateShootingPrice({
            calc_base_price: terms?.calc_base_price,
            calc_hourly_rate: terms?.calc_hourly_rate,
            calc_images_per_hour: terms?.calc_images_per_hour,
            calc_outdoor_multiplier: terms?.calc_outdoor_multiplier,
            duration: calcDuration,
            images: calcImages,
            isOutdoor: calcIsOutdoor,
            flatrate: calcIsFlatrate,
            discount: calcDiscount,
        });
        packagePriceEuro = res.packagePrice;
        finalPriceEuro = res.finalPrice;
        discountAbsolute = res.discountAbsolute;
    }

    const handleCalculate = () => {
        let desc: string;
        let notes: string;

        if (calcMode === 'srp') {
            const types = {portrait: 'Portrait', couple: 'Pärchen', nude: 'Akt & Boudoir'};
            const setups = {outdoor: 'Outdoor (Natur)', outdoor_flash: 'Mobiles Blitz-Setup', indoor: 'Fotostudio'};
            desc = `B2C Flex-Shooting (${types[srpType]})`;
            notes = `Setup: ${setups[srpSetup]} | Zusätzliche Bilder: ${srpExtra} | Online-Verbot: ${srpPrivate ? 'Ja' : 'Nein'}`;
        } else {
            desc = calcIsFlatrate ? 'Reportage / Flatrate-Shooting' : 'Individuelles Shooting-Paket';
            notes = `Custom Shooting Paket | ${calcIsOutdoor ? 'Outdoor' : 'Indoor'} | Dauer: ${calcDuration} Minuten | Inkludierte Bilder: ${calcImages} Stück.`;
        }

        const newItem: InvoiceItem = {type: 'item', description: desc, notes: notes, qty: 1, price: finalPriceEuro};
        let newDiscount: InvoiceDiscount | null = null;

        if (calcMode === 'rp' && calcDiscount !== '0') {
            const dName = calcDiscount === '33' ? 'N*xt Generation / Treue-Rabatt (~33%)' : 'Special Deal OGs (~50%)';
            const dNotes = calcDiscount === '33' ? 'Förderung für junge Talente.' : 'Partnerrabatt.';
            newDiscount = {type: 'discount_fixed', description: dName, notes: dNotes, price: discountAbsolute};
            newItem.price = packagePriceEuro;
        }

        onAddPackage(newItem, newDiscount);
        onClose();
    };

    return (
        <div className="modal modal-open z-[100]">
            <div className="modal-box relative max-w-lg">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                        onClick={onClose}>✕
                </button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--calculator text-primary"></span>
                    {calcMode === 'srp' ? 'B2C Flex-Paket Rechner' : 'B2B Shooting-Paket Kalkulator'}
                </h3>

                {isSrp && (
                    <div className="tabs tabs-boxed mb-4 w-full flex bg-base-200">
                        <button type="button" className={`tab flex-1 ${!usePremium ? 'tab-active font-bold' : ''}`} onClick={() => setUsePremium(false)}>B2C Flex-Paket</button>
                        <button type="button" className={`tab flex-1 ${usePremium ? 'tab-active font-bold' : ''}`} onClick={() => setUsePremium(true)}>Premium Tarif</button>
                    </div>
                )}

                {calcMode === 'srp' ? (
                    <div className="space-y-4">
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="label font-bold text-sm mb-1">Bereich</label>
                            <select className="select select-bordered w-full" value={srpType} onChange={e => {
                                setSrpType(e.target.value as 'portrait' | 'couple' | 'nude');
                                if (e.target.value !== 'nude') setSrpPrivate(false);
                            }}>
                                <option value="portrait">Portrait</option>
                                <option value="couple">Pärchen</option>
                                <option value="nude">Akt & Boudoir</option>
                            </select>
                        </div>
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="label font-bold text-sm mb-1">Setup</label>
                            <select className="select select-bordered w-full" value={srpSetup}
                                    onChange={e => setSrpSetup(e.target.value as 'outdoor' | 'outdoor_flash' | 'indoor')}>
                                <option value="outdoor">Outdoor (Natur)</option>
                                <option value="outdoor_flash">Mobiles Blitz-Setup (+50€)</option>
                                <option value="indoor">Fotostudio (+50€)</option>
                            </select>
                        </div>
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="label font-bold text-sm mb-1">Zusätzliche Bilder (+15€/Stk)</label>
                            <input type="number" min="0" step="1" className="input input-bordered w-full font-mono"
                                   value={srpExtra} onChange={e => setSrpExtra(parseInt(e.target.value) || 0)}/>
                        </div>
                        {srpType === 'nude' && (
                            <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                                <label className="cursor-pointer label justify-start gap-4 m-0 p-1">
                                    <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                           checked={srpPrivate} onChange={e => setSrpPrivate(e.target.checked)}/>
                                    <div>
                                        <span className="label-text font-bold block">Online-Verbot (Privacy Fee)</span>
                                        <span className="label-text-alt opacity-70 block mt-1 leading-tight text-wrap">Absolutes Veröffentlichungsverbot (+200€ zzgl. 5€/Extra-Bild)</span>
                                    </div>
                                </label>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div
                            className="grid grid-cols-2 gap-4 bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <div className="form-control">
                                <label className="label font-bold text-sm mb-1"><span className="label-text font-bold">Dauer (Min.)</span></label>
                                <input type="number" step="15" className="input input-bordered font-mono"
                                       value={calcDuration}
                                       onChange={e => setCalcDuration(parseInt(e.target.value) || 0)}/>
                            </div>
                            <div className="form-control">
                                <label className="label font-bold text-sm mb-1"><span className="label-text font-bold">Inkl. Bilder</span></label>
                                <input type="number" min="0" step="1" className="input input-bordered font-mono" value={calcImages}
                                       onChange={e => setCalcImages(parseInt(e.target.value) || 0)}/>
                            </div>
                        </div>
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="cursor-pointer label justify-start gap-4 m-0 p-1">
                                <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                       checked={calcIsOutdoor} onChange={e => setCalcIsOutdoor(e.target.checked)}/>
                                <span className="label-text font-bold">Outdoor-Shooting (Halbierter Bildpreis)</span>
                            </label>
                        </div>
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="cursor-pointer label justify-start gap-4 m-0 p-1">
                                <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                       checked={calcIsFlatrate} onChange={e => setCalcIsFlatrate(e.target.checked)}/>
                                <span className="label-text font-bold">Reportage-Paket (+20% Aufschlag)</span>
                            </label>
                        </div>
                        <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                            <label className="label font-bold text-sm mb-1">Rabatt-Stufe</label>
                            <select className="select select-bordered w-full" value={calcDiscount}
                                    onChange={e => setCalcDiscount(e.target.value as ShootingDiscount)}>
                                <option value="0">Kein Rabatt (0%)</option>
                                <option value="33">Studenten Rabatt (~33%)</option>
                                <option value="50">Special Deal OGs (~50%)</option>
                            </select>
                        </div>
                    </div>
                )}

                <div
                    className="bg-primary/5 p-4 rounded-box mt-8 border border-primary/20 flex justify-between items-center">
                    <span className="font-bold text-lg text-base-content">Wert:</span>
                    <div className="text-right">
                        {calcMode === 'rp' && calcDiscount !== '0' && <div
                            className="text-sm font-mono line-through opacity-50">{packagePriceEuro.toFixed(2)} €</div>}
                        <div className="text-2xl font-mono font-bold text-primary">{finalPriceEuro.toFixed(2)} €</div>
                    </div>
                </div>

                <div className="modal-action mt-6">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                    <button type="button" className="btn btn-primary px-6" onClick={handleCalculate}>Berechnen &
                        Hinzufügen
                    </button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}