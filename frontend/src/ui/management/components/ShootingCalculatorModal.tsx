import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useState} from 'react';
import {InvoiceDiscount, InvoiceItem} from '../../../api';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {calculateB2CFlexPrice, calculateShootingPrice, ShootingDiscount, DEFAULT_OUTDOOR_IMAGES_PER_HOUR} from '../../../logic/shootingCalculator';

interface ShootingCalculatorModalProps {
    isOpen: boolean;
    onClose: () => void;
    onAddPackage: (item: InvoiceItem, discount: InvoiceDiscount | null) => void;
}

export default function ShootingCalculatorModal({isOpen, onClose, onAddPackage}: ShootingCalculatorModalProps) {
    const {terms} = useLicenseTerms();

    const [useStandard, setUseStandard] = useState(true);
    const calcMode = useStandard ? 'rp' : 'flex';

    // --- RP State ---
    const [calcDuration, setCalcDuration] = useState<number>(90);
    const [calcImages, setCalcImages] = useState<number>(15);
    const [calcIsFlatrate, setCalcIsFlatrate] = useState<boolean>(false);
    const [calcIsOutdoor, setCalcIsOutdoor] = useState<boolean>(false);
    const [calcIsReorder, setCalcIsReorder] = useState<boolean>(false);
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

        if (calcMode === 'flex') {
        const res = calculateB2CFlexPrice({
            type: srpType,
            setup: srpSetup,
            extraImages: srpExtra,
            isFullyPrivate: srpPrivate,
            srp_base_price: terms?.srp_base_price ? String(Number(terms.srp_base_price) / 100) : undefined,
            srp_setup_fee: terms?.srp_setup_fee ? String(Number(terms.srp_setup_fee) / 100) : undefined,
            srp_privacy_fee: terms?.srp_privacy_fee ? String(Number(terms.srp_privacy_fee) / 100) : undefined,
            srp_extra_image_fee: terms?.srp_extra_image_fee ? String(Number(terms.srp_extra_image_fee) / 100) : undefined,
        });
        packagePriceEuro = res.packagePrice;
        finalPriceEuro = res.finalPrice;
        discountAbsolute = res.discountAbsolute;
    } else {
        const res = calculateShootingPrice({
            calc_base_price: terms?.calc_base_price,
            calc_hourly_rate: terms?.calc_hourly_rate,
            calc_images_per_hour: terms?.calc_images_per_hour,
            calc_outdoor_images_per_hour: terms?.calc_outdoor_images_per_hour,
            calc_flatrate_multiplier: terms?.calc_flatrate_multiplier,
            duration: calcDuration,
            images: calcImages,
            isOutdoor: calcIsOutdoor,
            flatrate: calcIsFlatrate,
            isReorder: calcIsReorder,
            discount: calcDiscount,
        });
        packagePriceEuro = res.packagePrice;
        finalPriceEuro = res.finalPrice;
        discountAbsolute = res.discountAbsolute;
    }

    const handleCalculate = () => {
        let desc: string;
        let notes: string;

    if (calcMode === 'flex') {
            const types = {portrait: t`Portrait`, couple: t`Pärchen`, nude: t`Akt & Boudoir`};
            const setups = {outdoor: t`Outdoor (Natur)`, outdoor_flash: t`Mobiles Blitz-Setup`, indoor: t`Fotostudio`};
            desc = `B2C Flex-Shooting (${types[srpType]})`;
            notes = `${t`Setup:`} ${setups[srpSetup]} | ${t`Zusätzliche Bilder:`} ${srpExtra} | ${t`Online-Verbot:`} ${srpPrivate ? t`Ja` : t`Nein`}`;
        } else {
            const baseDesc = calcIsFlatrate ? 'Reportage / Flatrate-Shooting' : 'Individuelles Shooting-Paket';
            desc = calcIsReorder ? `${baseDesc} (Nachbestellung)` : baseDesc;
            notes = `${calcIsOutdoor ? 'Outdoor' : 'Indoor'} | Dauer: ${calcDuration} Minuten | Inkludierte Bilder: ${calcImages} Stück.`;
        }

        const newItem: InvoiceItem = {type: 'item', description: desc, notes: notes, qty: 1, price: finalPriceEuro};
        let newDiscount: InvoiceDiscount | null = null;

        if (calcMode === 'rp' && calcDiscount !== '0') {
            const dName = calcDiscount === '33' ? 'Studentenrabatt (unter 26 Jahre)' : 'Special Deal OGs (~50%)';
            const dNotes = calcDiscount === '33' ? 'Rabatt für Studierende und Auszubildende (unter 26 Jahre).' : 'Partnerrabatt für langjährige Kunden.';
            newDiscount = {type: 'discount_fixed', description: dName, notes: dNotes, price: discountAbsolute};
            newItem.price = packagePriceEuro;
        }

        onAddPackage(newItem, newDiscount);
        onClose();
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box relative max-w-lg">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                        onClick={onClose}>✕
                </button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--calculator text-primary"></span>
                    {calcMode === 'flex' ? <Trans>Flex Tarif Rechner</Trans> : <Trans>Standard Tarif Rechner</Trans>}
                </h3>

                <div className="tabs tabs-lift">
                    <input type="radio" name="calc_tabs" className="tab" aria-label="Flex Tarif"
                           checked={!useStandard} onChange={() => setUseStandard(false)} />
                    <div className="tab-content bg-base-100 border-base-300 p-4">
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
                                <div className="label">
                                    <span className="label-text font-bold">Zusätzliche Bilder</span>
                                    <span className="label-text-alt font-mono">{srpExtra} Stk.</span>
                                </div>
                                <input type="range" min="0" max="50" step="1" className="range range-primary w-full"
                                       value={srpExtra} onChange={e => setSrpExtra(parseInt(e.target.value) || 0)}/>
                            </div>
                            {srpType === 'nude' && (
                                <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                                    <label className="cursor-pointer label justify-start gap-4 m-0 rounded-box hover:bg-base-300/50 transition-colors">
                                        <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                               checked={srpPrivate} onChange={e => setSrpPrivate(e.target.checked)}/>
                                        <div>
                                            <span className="label-text font-bold block">Online-Verbot (Privacy Fee)</span>
                                            <span className="label-text-alt opacity-70 block mt-1 leading-tight text-wrap">Absolutes Veröffentlichungsverbot (+200€)</span>
                                        </div>
                                    </label>
                                </div>
                            )}
                        </div>
                    </div>

                    <input type="radio" name="calc_tabs" className="tab" aria-label="Standard Tarif"
                           checked={useStandard} onChange={() => setUseStandard(true)} />
                    <div className="tab-content bg-base-100 border-base-300 p-4">
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
                                <label className="cursor-pointer label justify-start gap-4 m-0 rounded-box hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                           checked={calcIsOutdoor} onChange={e => setCalcIsOutdoor(e.target.checked)}/>
                                    <span className="label-text font-bold">Outdoor-Shooting (Bilder/Std.: {terms?.calc_outdoor_images_per_hour || DEFAULT_OUTDOOR_IMAGES_PER_HOUR})</span>
                                </label>
                            </div>
                            <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                                <label className="cursor-pointer label justify-start gap-4 m-0 rounded-box hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                           checked={calcIsFlatrate} onChange={e => setCalcIsFlatrate(e.target.checked)}/>
                                    <span className="label-text font-bold">Reportage-Paket (+{Math.round((parseFloat(terms?.calc_flatrate_multiplier || '1.2') - 1) * 100)}% Aufschlag)</span>
                                </label>
                            </div>
                            <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                                <label className="cursor-pointer label justify-start gap-4 m-0 rounded-box hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" className="checkbox checkbox-primary shrink-0"
                                           checked={calcIsReorder} onChange={e => setCalcIsReorder(e.target.checked)}/>
                                    <span className="label-text font-bold">Nachbestellung (keine Setup-Gebühr)</span>
                                </label>
                            </div>
                            <div className="form-control bg-base-100 p-3 rounded-box border border-base-300 shadow-sm">
                                <label className="label font-bold text-sm mb-1">Rabatt-Stufe</label>
                                <select className="select select-bordered w-full" value={calcDiscount}
                                        onChange={e => setCalcDiscount(e.target.value as ShootingDiscount)}>
                                    <option value="0">Kein Rabatt (0%)</option>
                                    <option value="33">Studentenrabatt (33%)</option>
                                    <option value="50">Special Deal OGs (50%)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

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
                    <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                    <button type="button" className="btn btn-primary px-6" onClick={handleCalculate}><Trans>Berechnen &
                        Hinzufügen</Trans>
                    </button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}