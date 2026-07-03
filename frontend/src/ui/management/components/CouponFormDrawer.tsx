import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';

export interface Coupon {
    id?: number;
    code: string;
    type: 'fixed' | 'percentage';
    value: number;
    max_items?: number;
    scope_type: 'global' | 'gallery' | 'meta_gallery' | 'photographer' | 'organisation';
    scope_id?: string;
    max_uses_global?: number;
    max_uses_per_account?: number;
    expires_at?: string;
    active: boolean;
    used_count: number;
    created_by?: string;
}

const couponSchema = z.object({
    code: z
        .string()
        .min(1, 'Code ist erforderlich')
        .max(50, 'Code darf maximal 50 Zeichen lang sein'),
    type: z.enum(['fixed', 'percentage']),
    value: z
        .number('Wert muss eine Zahl sein')
        .min(0, 'Wert darf nicht negativ sein'),
    max_items: z
        .number('Muss eine Zahl sein')
        .min(1, 'Muss mindestens 1 sein')
        .max(999, 'Darf maximal 999 sein')
        .optional(),
    scope_type: z.enum(['global', 'gallery', 'meta_gallery', 'photographer', 'organisation']),
    scope_id: z.string().optional(),
    max_uses_global: z
        .number('Muss eine Zahl sein')
        .min(1, 'Muss mindestens 1 sein')
        .optional(),
    max_uses_per_account: z
        .number('Muss eine Zahl sein')
        .min(1, 'Muss mindestens 1 sein')
        .optional(),
    expires_at: z.string().optional(),
    active: z.boolean()
});

type CouponFormValues = z.infer<typeof couponSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingCoupon?: Coupon | null;
    onSave: (data: Partial<Coupon>) => Promise<void>;
}

const TYPE_VALUE_LABEL: Record<CouponFormValues['type'], string> = {
    fixed: 'Betrag in €',
    percentage: 'Prozent',
};

const SCOPE_REQUIRES_TARGET: ReadonlySet<CouponFormValues['scope_type']> = new Set([
    'gallery',
    'meta_gallery',
    'organisation'
]);

function toFormValues(coupon?: Coupon | null): CouponFormValues {
    return {
        code: coupon?.code ?? '',
        type: coupon?.type ?? 'fixed',
        value: coupon?.value ?? 0,
        max_items: coupon?.max_items,
        scope_type: coupon?.scope_type ?? 'global',
        scope_id: coupon?.scope_id ?? '',
        max_uses_global: coupon?.max_uses_global,
        max_uses_per_account: coupon?.max_uses_per_account,
        expires_at: coupon?.expires_at ?? '',
        active: coupon?.active ?? true,
    };
}

function emptyToUndefined(value: string | undefined): string | undefined {
    return value && value.length > 0 ? value : undefined;
}

export default function CouponFormDrawer({ isOpen, onClose, editingCoupon, onSave }: Props) {
    const { confirm } = useUI();
    const {
        register,
        handleSubmit,
        reset,
        control,
        formState: { errors, isSubmitting, isDirty }
    } = useForm<CouponFormValues>({
        resolver: zodResolver(couponSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset(toFormValues(editingCoupon));
        }
    }, [isOpen, editingCoupon, reset]);

    const handleClose = async () => {
        if (isDirty) {
            const confirmed = await confirm({ title: 'Ungespeicherte Änderungen', message: 'Möchtest du die eingegebenen Daten wirklich verwerfen?', confirmText: 'Verwerfen', confirmColor: 'warning' });
            if (!confirmed) return;
        }
        onClose();
    };

    const watchType = useWatch({ control, name: 'type' });
    const watchScopeType = useWatch({ control, name: 'scope_type' });
    const watchActive = useWatch({ control, name: 'active' });

    const valueLabel = TYPE_VALUE_LABEL[watchType ?? 'fixed'];
    const showScopeTarget = watchScopeType !== undefined && SCOPE_REQUIRES_TARGET.has(watchScopeType);
    const showMaxItems = watchType === 'percentage';

    const onSubmit = async (data: CouponFormValues) => {
        const payload: Partial<Coupon> = {
            code: data.code.trim(),
            type: data.type,
            value: data.value,
            max_items: data.max_items,
            scope_type: data.scope_type,
            scope_id: emptyToUndefined(data.scope_id),
            max_uses_global: data.max_uses_global,
            max_uses_per_account: data.max_uses_per_account,
            expires_at: emptyToUndefined(data.expires_at),
            active: data.active,
        };
        await onSave(payload);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-2xl relative">
                <button
                    type="button"
                    className="btn btn-circle btn-ghost absolute right-2 top-2"
                    onClick={handleClose}
                    aria-label="Schließen"
                >
                    ✕
                </button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--ticket-percent-outline text-primary"></span>
                    {editingCoupon ? 'Rabattcode bearbeiten' : 'Neuen Rabattcode anlegen'}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Code *</span>
                            </label>
                            <input
                                type="text"
                                {...register('code')}
                                className={`input input-bordered uppercase ${errors.code ? 'input-error' : ''}`}
                                placeholder="z.B. SOMMER2026"
                            />
                            {errors.code && (
                                <span className="text-error text-xs mt-1">{errors.code.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Typ *</span>
                            </label>
                            <select {...register('type')} className="select select-bordered">
                                <option value="fixed">Festbetrag</option>
                                <option value="percentage">Prozent</option>
                            </select>
                            {errors.type && (
                                <span className="text-error text-xs mt-1">{errors.type.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">{valueLabel} *</span>
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                {...register('value', { valueAsNumber: true })}
                                className={`input input-bordered font-mono ${errors.value ? 'input-error' : ''}`}
                            />
                            {errors.value && (
                                <span className="text-error text-xs mt-1">{errors.value.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Geltungsbereich *</span>
                            </label>
                            <select {...register('scope_type')} className="select select-bordered">
                                <option value="global">Global</option>
                                <option value="gallery">Galerie</option>
                                <option value="meta_gallery">Galerie-Gruppe</option>
                                <option value="organisation">Organisation</option>
                                <option value="photographer">Fotograf</option>
                            </select>
                            {errors.scope_type && (
                                <span className="text-error text-xs mt-1">{errors.scope_type.message}</span>
                            )}
                        </div>

                        {showScopeTarget && (
                            <div className="form-control md:col-span-2">
                                <label className="label">
                                    <span className="label-text font-bold">Ziel-ID *</span>
                                </label>
                                <input
                                    type="text"
                                    {...register('scope_id')}
                                    className={`input input-bordered font-mono ${errors.scope_id ? 'input-error' : ''}`}
                                    placeholder={watchScopeType === 'gallery' ? 'Galerie-ID' : 'Galerie-Gruppen-ID'}
                                />
                                {errors.scope_id && (
                                    <span className="text-error text-xs mt-1">{errors.scope_id.message}</span>
                                )}
                            </div>
                        )}

                        {showMaxItems && (
                            <div className="form-control md:col-span-2">
                                <label className="label">
                                    <span className="label-text font-bold">Auf X günstigste Bilder beschränken</span>
                                    <span className="label-text-alt opacity-60">(optional)</span>
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    max="999"
                                    step="1"
                                    {...register('max_items', { valueAsNumber: true })}
                                    className={`input input-bordered font-mono ${errors.max_items ? 'input-error' : ''}`}
                                    placeholder="leer = auf gesamten Warenkorb"
                                />
                                {errors.max_items && (
                                    <span className="text-error text-xs mt-1">{errors.max_items.message}</span>
                                )}
                            </div>
                        )}

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Max. globale Verwendungen</span>
                            </label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                {...register('max_uses_global', { valueAsNumber: true })}
                                className={`input input-bordered font-mono ${errors.max_uses_global ? 'input-error' : ''}`}
                                placeholder="unbegrenzt"
                            />
                            {errors.max_uses_global && (
                                <span className="text-error text-xs mt-1">{errors.max_uses_global.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Max. Verwendungen pro Account</span>
                            </label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                {...register('max_uses_per_account', { valueAsNumber: true })}
                                className={`input input-bordered font-mono ${errors.max_uses_per_account ? 'input-error' : ''}`}
                                placeholder="unbegrenzt"
                            />
                            {errors.max_uses_per_account && (
                                <span className="text-error text-xs mt-1">{errors.max_uses_per_account.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Gültig bis</span>
                            </label>
                            <input
                                type="date"
                                {...register('expires_at')}
                                className="input input-bordered"
                            />
                        </div>

                        <div className="form-control">
                            <label className="label cursor-pointer justify-start gap-3">
                                <input
                                    type="checkbox"
                                    {...register('active')}
                                    className="checkbox checkbox-primary"
                                />
                                <span className="label-text font-bold">Aktiv</span>
                                <span className="label-text-alt opacity-60">
                                    {watchActive ? 'Gutscheincodes können eingelöst werden' : 'Gutscheincodes sind deaktiviert'}
                                </span>
                            </label>
                        </div>
                    </div>

                    <div className="modal-action col-span-full mt-6">
                        <button type="button" className="btn btn-ghost" onClick={handleClose}>
                            Abbrechen
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={handleClose}></div>
        </div>
    );
}
