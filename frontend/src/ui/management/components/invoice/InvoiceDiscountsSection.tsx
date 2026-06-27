import {InvoiceDiscount, Product} from '../../../../api';
import AutocompleteInput from '../../../components/AutocompleteInput';

interface InvoiceDiscountsSectionProps {
    discounts: InvoiceDiscount[];
    onDiscountChange: (index: number, field: string, value: string | number) => void;
    onAddDiscount: () => void;
    onRemoveDiscount: (index: number) => void;
}

export default function InvoiceDiscountsSection({
    discounts,
    onDiscountChange,
    onAddDiscount,
    onRemoveDiscount
}: InvoiceDiscountsSectionProps) {
    return (
        <div className="mt-6 border-t border-base-300 pt-6">
            <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                <h2 className="font-bold text-xl text-primary">Rabatte & Abzüge</h2>
                <button
                    type="button"
                    onClick={onAddDiscount}
                    className="btn btn-sm btn-outline btn-primary"
                >
                    + Rabatt hinzufügen
                </button>
            </div>

            <div className="space-y-4">
                {discounts.map((discount, idx) => (
                    <div
                        key={idx}
                        className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300"
                    >
                        <div className="form-control w-full md:w-1/4 shrink-0">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Art</span>
                            </label>
                            <select
                                value={discount.type}
                                onChange={(e) => onDiscountChange(idx, 'type', e.target.value)}
                                className="select select-sm select-bordered w-full bg-base-100"
                            >
                                <option value="discount_fixed">Fixer Betrag (€)</option>
                                <option value="discount_percent">Prozentual (%)</option>
                            </select>
                        </div>

                        <div className="form-control flex-1 w-full">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Titel / Beschreibung</span>
                            </label>
                            <AutocompleteInput<Product>
                                value={discount.description}
                                onChange={(val) => onDiscountChange(idx, 'description', val)}
                                endpoint="/api/management/products?type=discount_fixed,discount_percent&q="
                                mapResponse={(data) => data.map(p => ({
                                    id: p.id,
                                    title: p.name,
                                    subtitle: `${p.price.toFixed(2)} ${p.type === 'discount_percent' ? '%' : '€'}`,
                                    raw: p
                                }))}
                                onSelect={(p) => {
                                    onDiscountChange(idx, 'type', p.type || 'discount_fixed');
                                    onDiscountChange(idx, 'description', p.name);
                                    onDiscountChange(idx, 'notes', p.description || '');
                                    onDiscountChange(idx, 'price', p.type === 'discount_percent' ? p.price : p.price / 100);
                                }}
                                placeholder="z.B. Stammkundenrabatt"
                                className="input input-sm input-bordered w-full bg-base-100"
                            />
                        </div>

                        <div className="form-control w-full md:w-32 shrink-0">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Wert</span>
                            </label>
                            <div className="join w-full">
                                <input
                                    required
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={discount.price}
                                    onChange={(e) => onDiscountChange(idx, 'price', parseFloat(e.target.value) || 0)}
                                    className="input input-sm input-bordered join-item w-full font-mono text-right bg-base-100"
                                />
                                <span className="btn btn-sm btn-disabled join-item">
                                    {discount.type === 'discount_percent' ? '%' : '€'}
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() => onRemoveDiscount(idx)}
                            className="btn btn-sm btn-ghost text-error shrink-0 mt-7"
                        >
                            <span className="iconify mdi--trash-can text-lg"></span>
                        </button>
                    </div>
                ))}

                {discounts.length === 0 && (
                    <p className="text-sm opacity-50 italic px-2">Keine Rabatte angewendet.</p>
                )}
            </div>
        </div>
    );
}
