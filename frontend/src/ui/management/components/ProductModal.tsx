import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Product } from '../../../api';

const productSchema = z.object({
    type: z.enum(['item', 'discount_fixed', 'discount_percent']),
    name: z.string().min(1, t`Name ist erforderlich`),
    description: z.string().optional(),
    price: z.number().min(0, t`Wert muss positiv sein`)
});

type ProductFormValues = z.infer<typeof productSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingProduct?: Product | null;
    onSave: (data: Partial<Product>) => Promise<void>;
}

export default function ProductModal({ isOpen, onClose, editingProduct, onSave }: Props) {
    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<ProductFormValues>({
        resolver: zodResolver(productSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                type: editingProduct?.type || 'item',
                name: editingProduct?.name || '',
                description: editingProduct?.description || '',
                price: editingProduct ? editingProduct.price / 100 : 0
            });
        }
    }, [isOpen, editingProduct, reset]);

    const onSubmit = async (data: ProductFormValues) => {
        await onSave({ ...data, price: Math.round(Number(data.price) * 100) });
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--package-variant-closed text-primary"></span>
                    {editingProduct ? <Trans>Katalog-Eintrag bearbeiten</Trans> : <Trans>Neuen Eintrag anlegen</Trans>}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="grid grid-cols-1 md:grid-cols-2 gap-4" noValidate>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold"><Trans>Typ</Trans></span></label>
                        <select required {...register('type')} className="select select-bordered">
                            <option value="item">Leistung / Produkt</option>
                            <option value="discount_fixed">Rabatt (Fixbetrag in €)</option>
                            <option value="discount_percent">Rabatt (Prozentual in %)</option>
                        </select>
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold"><Trans>Titel / Name</Trans></span></label>
                        <input required type="text" {...register('name')} className={`input input-bordered ${errors.name ? 'input-error' : ''}`} />
                        {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold"><Trans>Zusatzbeschreibung</Trans></span></label>
                        <input type="text" {...register('description')} className="input input-bordered" />
                    </div>
                    <div className="form-control w-1/2">
                        <label className="label"><span className="label-text font-bold"><Trans>Standard-Wert</Trans></span></label>
                        <input required type="number" step="0.01" min="0" {...register('price', { valueAsNumber: true })} className={`input input-bordered font-mono ${errors.price ? 'input-error' : ''}`} />
                    </div>

                    <div className="modal-action col-span-full mt-6">
                        <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}