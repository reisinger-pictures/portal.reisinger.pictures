import {useState} from 'react';
import {InvoiceDiscount, InvoiceItem} from '../../../api';
import { useLicenseTerms } from '../../../logic/useLicenseTerms';
import {calculateShootingPrice} from '../../../logic/shootingCalculator';

// Definiere den exakten Typen für deine Rabatte
type DiscountOption = '0' | '33' | '50';

interface ShootingCalculatorModalProps {
    isOpen: boolean;
    onClose: () => void;
    onAddPackage: (item: InvoiceItem, discount: InvoiceDiscount | null) => void;
}

export default function ShootingCalculatorModal({isOpen, onClose, onAddPackage}: ShootingCalculatorModalProps) {
    const { terms } = useLicenseTerms();
    const [calcDuration, setCalcDuration] = useState<number>(90);
    const [calcImages, setCalcImages] = useState<number>(15);
    const [calcIsFlatrate, setCalcIsFlatrate] = useState<boolean>(false);
    const [calcDiscount, setCalcDiscount] = useState<DiscountOption>('0');

    if (!isOpen) return null;

    const handleDiscountChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const val = e.target.value;
        if (val === '0' || val === '33' || val === '50') {
            setCalcDiscount(val);
        }
    };

    // --- Live Calculation (Derived State) — reine Logik in shootingCalculator.ts ---
    const {packagePrice: packagePriceEuro, finalPrice: finalPriceEuro, discountAbsolute} = calculateShootingPrice({
        calc_base_price: terms?.calc_base_price,
        calc_hourly_rate: terms?.calc_hourly_rate,
        calc_images_per_hour: terms?.calc_images_per_hour,
        duration: calcDuration,
        images: calcImages,
        flatrate: calcIsFlatrate,
        discount: calcDiscount,
    });

    const handleCalculate = () => {
        const newItem: InvoiceItem = {
            type: 'item',
            description: calcIsFlatrate ? 'Reportage / Flatrate-Shooting' : 'Individuelles Shooting-Paket',
            notes: `Custom Shooting Paket | Dauer: ${calcDuration} Minuten | Inkludierte Bilder: ${calcImages} Stück.`,
            qty: 1,
            price: packagePriceEuro
        };

        let newDiscount: InvoiceDiscount | null = null;

        if (calcDiscount !== '0') {
            let discountName = '';
            let discountNotes = '';

            if (calcDiscount === '33') {
                discountName = 'N*xt Generation / Treue-Rabatt (~33%)';
                discountNotes = 'Förderung für junge Talente (18-25 J.) inkl. Veröffentlichungsfreigabe oder Treue-Bonus für Folgeaufträge.';
            } else if (calcDiscount === '50') {
                discountName = 'Special Deal OGs (~50%)';
                discountNotes = 'Partnerrabatt für langjährige Wegbegleiter inkl. unbeschränkter Veröffentlichungsfreigabe.';
            }

            newDiscount = {
                type: 'discount_fixed', // WICHTIG: Absoluter Euro-Abzug
                description: discountName,
                notes: discountNotes,
                price: discountAbsolute
            };
        }

        // Werte an die Eltern-Komponente übergeben
        onAddPackage(newItem, newDiscount);

        // Modal resetten & schließen
        setCalcDuration(90);
        setCalcImages(15);
        setCalcIsFlatrate(false);
        setCalcDiscount('0');
        onClose();
    };

    return (
        <div className="modal modal-open z-[100]">
            <div className="modal-box relative max-w-lg">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                        onClick={onClose}>✕
                </button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--calculator text-primary"></span> Shooting-Paket Kalkulator
                </h3>

                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Dauer (Minuten)</span></label>
                            <input type="number" min="0" step="15"
                                   className="input input-bordered w-full font-mono text-lg"
                                   value={calcDuration} onChange={e => setCalcDuration(parseInt(e.target.value) || 0)}/>
                        </div>
                        <div className="form-control">
                            <label className="label"><span
                                className="label-text font-bold">Inkludierte Bilder</span></label>
                            <input type="number" min="0" className="input input-bordered w-full font-mono text-lg"
                                   value={calcImages} onChange={e => setCalcImages(parseInt(e.target.value) || 0)}/>
                        </div>
                    </div>

                    <div className="form-control bg-base-200 p-3 rounded-box border border-base-300">
                        <label className="cursor-pointer label justify-start gap-4">
                            <input type="checkbox" className="checkbox checkbox-primary mt-1"
                                   checked={calcIsFlatrate} onChange={e => setCalcIsFlatrate(e.target.checked)}/>
                            <div>
                                <span className="label-text font-bold block">Reportage- / Flatrate-Paket</span>
                                <span className="label-text-alt opacity-70 block mt-1 leading-tight text-wrap">Wendet einen +20% Aufschlag auf den Grundpreis an.</span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control">
                        <label className="label"><span
                            className="label-text font-bold">Rabatt-Stufe anwenden</span></label>
                        <select className="select select-bordered w-full" value={calcDiscount}
                                onChange={handleDiscountChange}>
                            <option value="0">Kein Rabatt (0%)</option>
                            <option value="33">Studenten Rabatt (~33%)</option>
                            <option value="50">Special Deal OGs (~50%)</option>
                        </select>
                    </div>
                </div>

                {/* --- Live Preview --- */}
                <div className="bg-primary/5 p-4 rounded-box mt-8 border border-primary/20 flex justify-between items-center">
                    <span className="font-bold text-lg text-base-content">Voraussichtlicher Wert:</span>
                    <div className="text-right">
                        {calcDiscount !== '0' && (
                            <div className="text-sm font-mono line-through opacity-50">{packagePriceEuro.toFixed(2)} €</div>
                        )}
                        <div className="text-2xl font-mono font-bold text-primary">{finalPriceEuro.toFixed(2)} €</div>
                    </div>
                </div>

                <div className="modal-action mt-6">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                    <button type="button" className="btn btn-primary" onClick={handleCalculate}>Berechnen & Hinzufügen
                    </button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
