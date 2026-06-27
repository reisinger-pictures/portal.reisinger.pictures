import {useState} from 'react';
import {Product} from '../../../api';

interface Props {
    title: string;
    products: Product[];
    onEdit: (p: Product) => void;
    onDelete: (id: string) => void;
    onBatchSave: (updates: { id: string; description: string; price: number }[]) => Promise<void>;
}

export default function ProductBatchTable({title, products, onEdit, onDelete, onBatchSave}: Props) {
    const [isBatchMode, setIsBatchMode] = useState(false);
    const [batchData, setBatchData] = useState<Record<string, { description: string; price: number }>>({});
    const [isSaving, setIsSaving] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');

    const handleStartBatchMode = () => {
        const initial: Record<string, { description: string; price: number }> = {};
        products.forEach(p => {
            initial[p.id] = {description: p.description || '', price: p.price / 100};
        });
        setBatchData(initial);
        setIsBatchMode(true);
    };

    const updateField = (id: string, field: 'description' | 'price', value: string | number) => {
        setBatchData(prev => ({
            ...prev,
            [id]: {
                ...prev[id],
                [field]: value
            }
        }));
    };

    const handleSave = async () => {
        setIsSaving(true);
        const updates = Object.entries(batchData).map(([id, data]) => ({
            id,
            description: data.description,
            price: Math.round(data.price * 100)
        }));
        await onBatchSave(updates);
        setIsSaving(false);
        setIsBatchMode(false);
    };

    const filteredProducts = products.filter(p =>
        p.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (p.description || '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="bg-base-100 border border-base-300 rounded-box p-4 md:p-6 shadow-sm mb-6 last:mb-0">
            <h2 className="text-xl font-bold text-primary mb-3">{title} <span
                className="opacity-50 text-sm font-normal">({products.length})</span></h2>

            <div className="flex flex-col sm:flex-row items-center gap-2 mb-4">
                <div className="relative flex-1 w-full">
                    <span
                        className="iconify mdi--magnify absolute left-3 top-1/2 -translate-y-1/2 text-base-content/50"></span>
                    <input
                        type="text"
                        placeholder={`In ${title} suchen...`}
                        className="input input-sm input-bordered w-full pl-9"
                        value={searchTerm}
                        onChange={e => setSearchTerm(e.target.value)}
                    />
                </div>
                {products.length > 0 && (
                    <div className="shrink-0 w-full sm:w-auto">
                        {isBatchMode ? (
                            <div className="flex gap-2 w-full">
                                <button className="btn btn-sm btn-ghost flex-1 sm:flex-none"
                                        onClick={() => setIsBatchMode(false)} disabled={isSaving}>Abbrechen
                                </button>
                                <button className="btn btn-sm btn-primary flex-1 sm:flex-none" onClick={handleSave}
                                        disabled={isSaving}>
                                    {isSaving ? <span className="loading loading-spinner"></span> : 'Speichern'}
                                </button>
                            </div>
                        ) : (
                            <button className="btn btn-sm btn-outline btn-primary w-full"
                                    onClick={handleStartBatchMode}>
                                <span className="iconify mdi--table-edit mr-1 text-lg"></span> Batch Edit
                            </button>
                        )}
                    </div>
                )}
            </div>

            {/* DESKTOP VIEW (Table) */}
            <div className="hidden md:block overflow-x-auto">
                <table className="table table-zebra w-full">
                    <thead>
                    <tr>
                        <th className="w-[35%]">Name</th>
                        <th className="w-[45%]">Beschreibung</th>
                        <th className="text-right w-28">Wert</th>
                        {!isBatchMode && <th className="text-right w-24">Aktionen</th>}
                    </tr>
                    </thead>
                    <tbody>
                    {filteredProducts.map(p => (
                        <tr key={p.id}>
                            <td>
                                <div className="font-bold text-base-content">{p.name}</div>
                                <div className="mt-1.5">
                                    {p.type === 'item' && <span className="badge badge-info badge-xs">Leistung</span>}
                                    {p.type === 'discount_fixed' &&
                                        <span className="badge badge-warning badge-xs">Rabatt (€)</span>}
                                    {p.type === 'discount_percent' &&
                                        <span className="badge badge-warning badge-xs">Rabatt (%)</span>}
                                </div>
                            </td>
                            <td>
                                {isBatchMode ? (
                                    <input
                                        type="text"
                                        className="input input-sm input-bordered w-full"
                                        value={batchData[p.id]?.description ?? ''}
                                        onChange={e => updateField(p.id, 'description', e.target.value)}
                                        placeholder="Optional"
                                    />
                                ) : (
                                    <div className="text-sm opacity-70 leading-relaxed">{p.description || '-'}</div>
                                )}
                            </td>
                            <td className="text-right align-middle">
                                {isBatchMode ? (
                                    <label
                                        className="input input-sm input-bordered flex items-center gap-2 w-28 ml-auto">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            className="grow text-right font-mono min-w-0"
                                            value={batchData[p.id]?.price ?? 0}
                                            onChange={e => updateField(p.id, 'price', parseFloat(e.target.value) || 0)}
                                        />
                                        <span
                                            className="opacity-60 font-bold shrink-0">{p.type === 'discount_percent' ? '%' : '€'}</span>
                                    </label>
                                ) : (
                                    <div className="font-mono font-bold text-primary whitespace-nowrap">
                                        {(p.price / 100).toFixed(2)} {p.type === 'discount_percent' ? '%' : '€'}
                                    </div>
                                )}
                            </td>
                            {!isBatchMode && (
                                <td className="text-right align-middle">
                                    <div className="flex justify-end gap-1">
                                        <button className="btn btn-ghost btn-xs btn-square" title="Bearbeiten"
                                                onClick={() => onEdit(p)}>
                                            <span className="iconify mdi--pencil text-base"></span>
                                        </button>
                                        <button className="btn btn-ghost btn-xs btn-square text-error"
                                                onClick={() => onDelete(p.id)} title="Löschen">
                                            <span className="iconify mdi--trash-can text-base"></span>
                                        </button>
                                    </div>
                                </td>
                            )}
                        </tr>
                    ))}
                    {filteredProducts.length === 0 && (
                        <tr>
                            <td colSpan={isBatchMode ? 3 : 4} className="text-center py-8 opacity-50">Keine Einträge
                                gefunden.
                            </td>
                        </tr>
                    )}
                    </tbody>
                </table>
            </div>

            {/* MOBILE VIEW (Cards) */}
            <div className="md:hidden flex flex-col gap-4">
                {filteredProducts.map(p => (
                    <div key={p.id}
                         className="bg-base-200/50 border border-base-300 rounded-box p-4 relative flex flex-col gap-4 shadow-sm">
                        {!isBatchMode && (
                            <div className="absolute top-3 right-3 flex gap-1">
                                <button className="btn btn-ghost btn-sm btn-square h-8 w-8 min-h-0"
                                        onClick={() => onEdit(p)}>
                                    <span className="iconify mdi--pencil text-lg"></span>
                                </button>
                                <button className="btn btn-ghost btn-sm btn-square h-8 w-8 min-h-0 text-error"
                                        onClick={() => onDelete(p.id)}>
                                    <span className="iconify mdi--trash-can text-lg"></span>
                                </button>
                            </div>
                        )}
                        <div className={!isBatchMode ? "pr-16" : ""}>
                            <div className="font-bold text-base leading-tight mb-2 text-base-content">{p.name}</div>
                            <div className="flex flex-wrap gap-1">
                                {p.type === 'item' && <span className="badge badge-info badge-xs">Leistung</span>}
                                {p.type === 'discount_fixed' &&
                                    <span className="badge badge-warning badge-xs">Rabatt (€)</span>}
                                {p.type === 'discount_percent' &&
                                    <span className="badge badge-warning badge-xs">Rabatt (%)</span>}
                            </div>
                        </div>

                        <div className="flex flex-col gap-1">
                            <span className="text-sm font-bold opacity-70">Beschreibung</span>
                            {isBatchMode ? (
                                <input
                                    type="text"
                                    className="input input-sm input-bordered w-full"
                                    value={batchData[p.id]?.description ?? ''}
                                    onChange={e => updateField(p.id, 'description', e.target.value)}
                                    placeholder="Optional"
                                />
                            ) : (
                                <div className="text-sm opacity-80 leading-relaxed">{p.description ||
                                    <span className="italic opacity-40">Keine Beschreibung</span>}</div>
                            )}
                        </div>

                        <div className="flex flex-col gap-1">
                            <span className="text-sm font-bold opacity-70">Wert</span>
                            {isBatchMode ? (
                                <label className="input input-sm input-bordered flex items-center gap-2 w-full">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="grow text-right font-mono min-w-0"
                                        value={batchData[p.id]?.price ?? 0}
                                        onChange={e => updateField(p.id, 'price', parseFloat(e.target.value) || 0)}
                                    />
                                    <span
                                        className="opacity-60 font-bold shrink-0">{p.type === 'discount_percent' ? '%' : '€'}</span>
                                </label>
                            ) : (
                                <div className="font-mono font-bold text-primary text-lg">
                                    {(p.price / 100).toFixed(2)} {p.type === 'discount_percent' ? '%' : '€'}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
                {filteredProducts.length === 0 && (
                    <div
                        className="text-center py-8 opacity-50 border border-dashed border-base-300 rounded-box text-sm">Keine
                        Einträge gefunden.</div>
                )}
            </div>

            {/* Speichern/Abbrechen am Listen-Ende (Nur im Batch Modus) */}
            {isBatchMode && products.length > 0 && (
                <div className="mt-4 pt-4 border-t border-base-300 flex justify-end gap-2">
                    <button className="btn btn-sm btn-ghost" onClick={() => setIsBatchMode(false)}
                            disabled={isSaving}>Abbrechen
                    </button>
                    <button className="btn btn-sm btn-primary" onClick={handleSave} disabled={isSaving}>
                        {isSaving ? <span className="loading loading-spinner"></span> : 'Speichern'}
                    </button>
                </div>
            )}
        </div>
    );
}
