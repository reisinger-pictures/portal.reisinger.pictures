import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Product } from '../../../api';
import ModalDialogShell from '../../components/ModalDialogShell';

const createProductSchema = () => z.object({
    type: z.enum(['item', 'discount_fixed', 'discount_percent']),
    name: z.string().min(1, t`Name ist erforderlich`),
    description: z.string().optional(),
    price: z.number().min(0, t`Wert muss positiv sein`)
});

type ProductFormValues = z.infer<ReturnType<typeof createProductSchema>>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingProduct?: Product | null;
    onSave: (data: Partial<Product>) => Promise<void>;
}

// Renamed on destructuring, see TextSnippetModal: the record is `product`, and
// the shell's delete button follows `onDelete` (D-20), which this dialog does
// not pass.
export default function ProductModal({ isOpen, onClose, editingProduct: product, onSave }: Props) {
    "use no memo";
    const productSchema = createProductSchema();
    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<ProductFormValues>({
        resolver: zodResolver(productSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                type: product?.type || 'item',
                name: product?.name || '',
                description: product?.description || '',
                price: product ? product.price / 100 : 0
            });
        }
    }, [isOpen, product, reset]);

    const onSubmit = async (data: ProductFormValues) => {
        try {
            await onSave({ ...data, price: Math.round(Number(data.price) * 100) });
            onClose();
        } catch {
            // The parent reports the API error; keep the entered values and modal open.
            return;
        }
    };

    if (!isOpen) return null;

    return (
        <ModalDialogShell
            title={product ? <Trans>Katalog-Eintrag bearbeiten</Trans> : <Trans>Neuen Eintrag anlegen</Trans>}
            icon="mdi--package-variant-closed"
            onClose={onClose}
            // No delete action for an existing entry, so no `onDelete` and the
            // shell renders no delete button. The edit session is `product`, an
            // unrelated value. See TextSnippetModal.
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
            noValidate
            className="z-50"
        >
                {/* The two-column grid used to sit on the <form> itself. The
                    shell owns the form and leaves it unclassed, so the grid
                    moves onto a wrapper around the fields; the two columns and
                    the field order are unchanged. The submit row leaves the grid
                    entirely and is rendered by the shared footer. */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                </div>
        </ModalDialogShell>
    );
}