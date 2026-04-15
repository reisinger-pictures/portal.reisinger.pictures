import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import ProductModal from './components/ProductModal';

export interface Product {
    id: string;
    type: 'item' | 'discount_fixed' | 'discount_percent';
    name: string;
    description?: string | null;
    price: number;
}

export default function ManagementProductsView() {
    const { data: products, error, isLoading, mutate } = useSWR<Product[]>('/api/management/products', fetcher);
    const { showToast, confirm } = useUI();
    const [searchTerm, setSearchTerm] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);

    const handleSave = async (data: Partial<Product>) => {
        try {
            if (editingProduct) {
                await apiMutate(`/api/management/products/${editingProduct.id}`, 'PUT', data);
                showToast('success', 'Eintrag aktualisiert');
            } else {
                await apiMutate('/api/management/products', 'POST', data);
                showToast('success', 'Eintrag angelegt');
            }
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Speichern');
        }
    };

    const openEdit = (p: Product) => {
        setEditingProduct(p);
        setIsModalOpen(true);
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: 'Eintrag löschen?', message: 'Möchtest du diesen Eintrag wirklich entfernen?', confirmColor: 'error' }))) return;
        try {
            await apiMutate(`/api/management/products/${id}`, 'DELETE');
            mutate();
            showToast('success', 'Eintrag gelöscht');
        } catch {
            showToast('error', 'Fehler beim Löschen');
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message="Fehler beim Laden." /></div>;

    const filtered = products?.filter(p => 
        p.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
        (p.description || '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="p-6 md:p-10 max-w-5xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2">Produkte & Rabatte</h1>
                    <p className="opacity-70">Verwalte deinen Katalog für manuelle Angebote und Rechnungen.</p>
                </div>
                <button className="btn btn-primary" onClick={() => { setEditingProduct(null); setIsModalOpen(true); }}>+ Neuer Eintrag</button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm">
                <input 
                    type="text" 
                    placeholder="Suchen..." 
                    className="input input-bordered w-full md:w-1/2 mb-6" 
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                />

                <div className="overflow-x-auto">
                    <table className="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>Name / Beschreibung</th>
                                <th className="text-right">Standard-Wert</th>
                                <th className="text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered?.map(p => (
                                <tr key={p.id}>
                                    <td>
                                        {p.type === 'item' && <span className="badge badge-info badge-sm">Leistung</span>}
                                        {p.type === 'discount_fixed' && <span className="badge badge-warning badge-sm">Rabatt (€)</span>}
                                        {p.type === 'discount_percent' && <span className="badge badge-warning badge-sm">Rabatt (%)</span>}
                                    </td>
                                    <td>
                                        <div className="font-bold">{p.name}</div>
                                        <div className="text-xs opacity-70">{p.description || '-'}</div>
                                    </td>
                                    <td className="text-right font-mono font-bold text-primary">
                                        {p.price.toFixed(2)} {p.type === 'discount_percent' ? '%' : '€'}
                                    </td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <button className="btn btn-ghost btn-xs btn-square" title="Bearbeiten" onClick={() => openEdit(p)}><span className="iconify mdi--pencil text-base"></span></button>
                                            <button className="btn btn-ghost btn-xs btn-square text-error" onClick={() => handleDelete(p.id)} title="Löschen"><span className="iconify mdi--trash-can text-base"></span></button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {filtered?.length === 0 && (
                                <tr><td colSpan={4} className="text-center py-10 opacity-50">Keine Einträge gefunden.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            <ProductModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} editingProduct={editingProduct} onSave={handleSave} />
        </div>
    );
}
