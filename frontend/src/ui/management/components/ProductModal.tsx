import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { Product } from '../ManagementProductsView';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingProduct?: Product | null;
    onSave: (data: Partial<Product>) => Promise<void>;
}

export default function ProductModal({ isOpen, onClose, editingProduct, onSave }: Props) {
    const { register, handleSubmit, reset, formState: { isSubmitting } } = useForm<Partial<Product>>();

    useEffect(() => {
        if (isOpen) {
            reset({
                name: editingProduct?.name || '',
                description: editingProduct?.description || '',
                price: editingProduct?.price || 0
            });
        }
    }, [isOpen, editingProduct, reset]);

    const onSubmit = async (data: Partial<Product>) => {
        await onSave(data);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box relative">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--package-variant-closed text-primary"></span>
                    {editingProduct ? 'Produkt bearbeiten' : 'Neues Produkt anlegen'}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Titel / Name *</span></label>
                        <input required type="text" {...register('name')} className="input input-sm input-bordered" />
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Zusatzbeschreibung (kleingedruckt)</span></label>
                        <input type="text" {...register('description')} className="input input-sm input-bordered" />
                    </div>
                    <div className="form-control w-1/2">
                        <label className="label"><span className="label-text font-bold">Standard-Preis (Netto) *</span></label>
                        <input required type="number" step="any" min="0" {...register('price', { valueAsNumber: true })} className="input input-sm input-bordered font-mono" />
                    </div>

                    <div className="modal-action mt-6">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
