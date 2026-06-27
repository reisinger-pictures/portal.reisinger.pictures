import {InvoiceItem, Product} from '../../../../api';
import AutocompleteInput from '../../../components/AutocompleteInput';

interface InvoiceItemsTableProps {
    items: InvoiceItem[];
    onItemChange: (index: number, field: string, value: string | number) => void;
    onAddItem: () => void;
    onRemoveItem: (index: number) => void;
    onMoveItemUp: (index: number) => void;
    onMoveItemDown: (index: number) => void;
}

export default function InvoiceItemsTable({
    items,
    onItemChange,
    onAddItem,
    onRemoveItem,
    onMoveItemUp,
    onMoveItemDown
}: InvoiceItemsTableProps) {
    return (
        <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
            <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                <h2 className="font-bold text-xl text-primary">Leistungen / Positionen</h2>
                <button type="button" onClick={onAddItem} className="btn btn-sm btn-outline btn-primary">
                    + Leistung hinzufügen
                </button>
            </div>

            <div className="space-y-4">
                {items.map((item, idx) => (
                    <div key={idx} className="flex flex-col md:flex-row gap-3 items-start p-3 bg-base-200 rounded-box border border-base-300">
                        <div className="flex flex-col gap-1 self-center shrink-0 mr-2">
                            <button
                                type="button"
                                onClick={() => onMoveItemUp(idx)}
                                disabled={idx === 0}
                                className="btn btn-xs btn-ghost btn-square"
                            >
                                <span className="iconify mdi--arrow-up text-lg opacity-50"></span>
                            </button>
                            <button
                                type="button"
                                onClick={() => onMoveItemDown(idx)}
                                disabled={idx === items.length - 1}
                                className="btn btn-xs btn-ghost btn-square"
                            >
                                <span className="iconify mdi--arrow-down text-lg opacity-50"></span>
                            </button>
                        </div>

                        <div className="form-control flex-1 w-full">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Titel / Name</span>
                            </label>
                            <AutocompleteInput<Product>
                                value={item.description}
                                onChange={(val) => onItemChange(idx, 'description', val)}
                                endpoint="/api/management/products?type=item&q="
                                mapResponse={(data) => data.map(p => ({
                                    id: p.id,
                                    title: p.name,
                                    subtitle: `${p.price.toFixed(2)} €`,
                                    raw: p
                                }))}
                                onSelect={(p) => {
                                    onItemChange(idx, 'description', p.name);
                                    onItemChange(idx, 'notes', p.description || '');
                                    onItemChange(idx, 'price', p.price / 100);
                                }}
                                placeholder="z.B. Fotoshooting"
                                className="input input-sm input-bordered w-full"
                            />
                        </div>

                        <div className="form-control flex-1 w-full">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Zusatz (kleingedruckt)</span>
                            </label>
                            <input
                                type="text"
                                value={item.notes}
                                onChange={(e) => onItemChange(idx, 'notes', e.target.value)}
                                className="input input-sm input-bordered w-full"
                                placeholder="Optional"
                            />
                        </div>

                        <div className="form-control w-20 shrink-0">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Menge</span>
                            </label>
                            <input
                                required
                                type="number"
                                step="0.25"
                                min="0.25"
                                value={item.qty}
                                onChange={(e) => onItemChange(idx, 'qty', parseFloat(e.target.value) || 0)}
                                className="input input-sm input-bordered w-full font-mono text-center"
                            />
                        </div>

                        <div className="form-control w-full md:w-28 shrink-0">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Preis / Stück</span>
                            </label>
                            <input
                                required
                                type="number"
                                step="0.01"
                                value={item.price}
                                onChange={(e) => onItemChange(idx, 'price', parseFloat(e.target.value) || 0)}
                                className="input input-sm input-bordered w-full font-mono text-right"
                            />
                        </div>

                        <div className="form-control w-full md:w-28 shrink-0">
                            <label className="label py-1">
                                <span className="label-text text-sm font-bold">Gesamt</span>
                            </label>
                            <div className="text-right font-mono font-bold mt-1 text-base-content">
                                {(item.price * item.qty).toFixed(2)} €
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() => onRemoveItem(idx)}
                            className="btn btn-sm btn-ghost text-error shrink-0 mt-7"
                        >
                            <span className="iconify mdi--trash-can text-lg"></span>
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}
