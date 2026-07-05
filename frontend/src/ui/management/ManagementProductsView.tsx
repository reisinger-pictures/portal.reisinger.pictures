import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import ProductModal from './components/ProductModal';
import ProductBatchTable, { BatchUpdate } from './components/ProductBatchTable';
import { Product } from '../../api';

export default function ManagementProductsView() {
    const { data: products, error, isLoading, mutate } = useSWR<Product[]>('/api/management/products', fetcher);
    const { showToast, confirm } = useUI();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState<Product | null>(null);

    const handleSave = async (data: Partial<Product>) => {
        try {
            if (editingProduct) {
                await apiMutate(`/api/management/products/${editingProduct.id}`, 'PUT', data);
                showToast('success', t`Eintrag aktualisiert`);
            } else {
                await apiMutate('/api/management/products', 'POST', data);
                showToast('success', t`Eintrag angelegt`);
            }
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Speichern`);
        }
    };

    const handleBatchSave = async (updates: BatchUpdate[]) => {
        try {
            await Promise.all(updates.map(u => {
                const original = products?.find(p => p.id === u.id);
                if (!original) return Promise.resolve();
                // Nur updaten wenn sich etwas geändert hat
                if (original.description === u.description && original.price === u.price) return Promise.resolve();
                
                return apiMutate(`/api/management/products/${u.id}`, 'PUT', {
                    type: original.type,
                    name: original.name,
                    description: u.description,
                    price: u.price
                });
            }));
            showToast('success', t`Einträge erfolgreich aktualisiert`);
            mutate();
        } catch {
            showToast('error', t`Fehler beim Speichern einiger Einträge`);
        }
    };

    const openEdit = (p: Product) => {
        setEditingProduct(p);
        setIsModalOpen(true);
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: t`Eintrag löschen?`, message: t`Möchtest du diesen Eintrag wirklich entfernen?`, confirmColor: 'error' }))) return;
        try {
            await apiMutate(`/api/management/products/${id}`, 'DELETE');
            mutate();
            showToast('success', t`Eintrag gelöscht`);
        } catch {
            showToast('error', t`Fehler beim Löschen`);
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden.`} /></div>;

    const items = products?.filter(p => p.type === 'item') || [];
    const discounts = products?.filter(p => p.type !== 'item') || [];

    return (
        <div className="p-6 md:p-10 max-w-5xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2"><Trans>Produkte & Rabatte</Trans></h1>
                    <p className="opacity-70"><Trans>Verwalte deinen Katalog für manuelle Angebote und Rechnungen.</Trans></p>
                </div>
                <button className="btn btn-primary" onClick={() => { setEditingProduct(null); setIsModalOpen(true); }}>+ <Trans>Neuer Eintrag</Trans></button>
            </div>

            <ProductBatchTable title={t`Leistungen & Produkte`} products={items} onEdit={openEdit} onDelete={handleDelete} onBatchSave={handleBatchSave} />
            <ProductBatchTable title={t`Rabatte & Abzüge`} products={discounts} onEdit={openEdit} onDelete={handleDelete} onBatchSave={handleBatchSave} />
            
            <ProductModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} editingProduct={editingProduct} onSave={handleSave} />
        </div>
    );
}
