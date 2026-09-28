import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';
import ModalDialogShell from '../../components/ModalDialogShell';

export interface Coupon {
    id?: number;
    code: string;
    type: 'fixed' | 'percentage' | 'photo_package';
    value: number;
    max_items?: number;
    /** Photo-package: number of photos (N). */
    package_quantity?: number;
    /** Photo-package: flat price Y in cents (Stripe-conform). */
    package_price_cents?: number;
    scope_type: 'global' | 'gallery' | 'meta_gallery' | 'photographer' | 'organisation';
    scope_id?: string;
    max_uses_global?: number;
    max_uses_per_account?: number;
    expires_at?: string;
    active: boolean;
    used_count: number;
    created_by?: string;
}

// Schema is built inside the component body (factory) so the Lingui `t` macros
// run at render time — module-scope `t` crashes the production bundle.
const createCouponSchema = () => z.object({
    code: z
        .string()
        .min(1, t`Code ist erforderlich`)
        .max(50, t`Code darf maximal 50 Zeichen lang sein`),
    type: z.enum(['fixed', 'percentage', 'photo_package']),
    value: z
        .number(t`Wert muss eine Zahl sein`)
        .min(0, t`Wert darf nicht negativ sein`)
        .optional(),
    max_items: z
        .number(t`Muss eine Zahl sein`)
        .min(1, t`Muss mindestens 1 sein`)
        .max(999, t`Darf maximal 999 sein`)
        .optional(),
    package_quantity: z
        .number(t`Muss eine Zahl sein`)
        .min(1, t`Muss mindestens 1 sein`)
        .optional(),
    package_price_cents: z
        .number(t`Muss eine Zahl sein`)
        .min(0, t`Darf nicht negativ sein`)
        .optional(),
    scope_type: z.enum(['global', 'gallery', 'meta_gallery', 'photographer', 'organisation']),
    scope_id: z.string().optional(),
    max_uses_global: z
        .number(t`Muss eine Zahl sein`)
        .min(1, t`Muss mindestens 1 sein`)
        .optional(),
    max_uses_per_account: z
        .number(t`Muss eine Zahl sein`)
        .min(1, t`Muss mindestens 1 sein`)
        .optional(),
    expires_at: z.string().optional(),
    active: z.boolean(),
}).superRefine((val, ctx) => {
    if (val.type === 'photo_package') {
        if (val.package_quantity == null || Number.isNaN(val.package_quantity) || val.package_quantity < 1) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['package_quantity'],
                message: t`Anzahl Fotos muss mindestens 1 sein`,
            });
        }
        if (val.package_price_cents == null || Number.isNaN(val.package_price_cents) || val.package_price_cents < 0) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['package_price_cents'],
                message: t`Festpreis darf nicht negativ sein`,
            });
        }
    }
});

// React Hook Form's `valueAsNumber` yields `NaN` (not `undefined`) for empty
// number inputs, which `z.number().optional()` rejects. Map '' / null / NaN →
// undefined so optional number fields stay truly optional.
const toOptionalNumber = (v: unknown): number | undefined =>
    v === '' || v == null || (typeof v === 'number' && Number.isNaN(v)) ? undefined : Number(v);

type CouponFormValues = z.infer<ReturnType<typeof createCouponSchema>>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingCoupon?: Coupon | null;
    onSave: (data: Partial<Coupon>) => Promise<void>;
}

const SCOPE_REQUIRES_TARGET: ReadonlySet<CouponFormValues['scope_type']> = new Set([
    'gallery',
    'meta_gallery',
    'organisation'
]);

function toFormValues(coupon?: Coupon | null): CouponFormValues {
    return {
        code: coupon?.code ?? '',
        type: coupon?.type ?? 'fixed',
        // The stored unit depends on `type`: cents for `fixed`, percent for
        // `percentage`. Both are entered in the unit the admin reads (€ / %),
        // and converted at the form boundary — see `onSubmit`. A single
        // division here would be wrong for `percentage`, which is not money.
        value: coupon?.value != null
            ? (coupon.type === 'fixed' ? coupon.value / 100 : coupon.value)
            : 0,
        max_items: coupon?.max_items,
        package_quantity: coupon?.package_quantity,
        // Cents in, cents out: the form is the only place that knows this is
        // an amount an admin types in euros.
        package_price_cents: coupon?.package_price_cents != null ? coupon.package_price_cents / 100 : undefined,
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

// Renamed on destructuring, see TextSnippetModal: the record is `coupon`, and
// the shell's delete button follows `onDelete` (D-20), which this dialog does
// not pass.
export default function CouponFormDrawer({ isOpen, onClose, editingCoupon: coupon, onSave }: Props) {
    "use no memo";
    const { confirm } = useUI();
    const {
        register,
        handleSubmit,
        reset,
        control,
        formState: { errors, isSubmitting, isDirty }
    } = useForm<CouponFormValues>({
        resolver: zodResolver(createCouponSchema())
    });

    useEffect(() => {
        if (isOpen) {
            reset(toFormValues(coupon));
        }
    }, [isOpen, coupon, reset]);

    const handleClose = async () => {
        if (isDirty) {
            const confirmed = await confirm({ title: t`Ungespeicherte Änderungen`, message: t`Möchtest du die eingegebenen Daten wirklich verwerfen?`, confirmText: t`Verwerfen`, confirmColor: 'warning' });
            if (!confirmed) return;
        }
        onClose();
    };

    const watchType = useWatch({ control, name: 'type' });
    const watchScopeType = useWatch({ control, name: 'scope_type' });
    const watchActive = useWatch({ control, name: 'active' });

    const valueLabel = (watchType ?? 'fixed') === 'percentage' ? t`Prozent` : t`Betrag in €`;
    const showScopeTarget = watchScopeType !== undefined && SCOPE_REQUIRES_TARGET.has(watchScopeType);
    const showMaxItems = watchType === 'percentage';
    const showPackageFields = watchType === 'photo_package';

    const onSubmit = async (data: CouponFormValues) => {
        const payload: Partial<Coupon> = {
            code: data.code.trim(),
            type: data.type,
            // The wire is cents (owner decision 2026-09-28), so the euro the
            // admin typed is converted here and nowhere else. For `percentage`
            // the value passes through untouched — it is a percent, and
            // multiplying it by 100 would turn 10 % into 1000 %.
            value: data.value == null
                ? undefined
                : (data.type === 'fixed' ? Math.round(data.value * 100) : data.value),
            max_items: data.max_items,
            package_quantity: data.package_quantity,
            // Same boundary for the package price: euro in the field, cents on
            // the wire. The backend no longer scales this value.
            package_price_cents: data.package_price_cents == null
                ? undefined
                : Math.round(data.package_price_cents * 100),
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
        <ModalDialogShell
            title={coupon ? <Trans>Rabattcode bearbeiten</Trans> : <Trans>Neuen Rabattcode anlegen</Trans>}
            icon="mdi--ticket-percent-outline"
            // `handleClose`, not `onClose`: it asks for confirmation whenever
            // the form is dirty. The shell routes its header button, its
            // backdrop, Escape and the cancel button all through this one prop,
            // so all four now raise that confirmation — Escape and the backdrop
            // previously discarded a dirty form with no warning, which was the
            // one place this dialog could lose typed input silently.
            onClose={handleClose}
            // No delete action for an existing coupon — codes are deactivated
            // through the `Aktiv` checkbox — so no `onDelete` and the shell
            // renders no delete button. The edit session is `coupon`, an
            // unrelated value. See TextSnippetModal.
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
            noValidate
            className="z-50"
            // See CustomerModal: `max-w-2xl` is also what `maxWidth="2xl"`
            // renders, so this stays on `boxClassName` with the markup it has
            // always had.
            boxClassName="max-w-2xl"
            // Eleven form-controls at the `photo_package` setting, so this
            // dialog genuinely outgrows a viewport. Opting in keeps the submit
            // row reachable instead of below the fold, which is what a long
            // *form* needs from the bounded layout.
            scrollableBody
        >
                <div className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Code</Trans></span>
                            </label>
                            <input
                                type="text"
                                required
                                {...register('code')}
                                className={`input input-bordered uppercase ${errors.code ? 'input-error' : ''}`}
                                placeholder={t`z.B. SOMMER2026`}
                            />
                            {errors.code && (
                                <span className="text-error text-xs mt-1">{errors.code.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Typ</Trans></span>
                            </label>
                            <select required {...register('type')} className="select select-bordered">
                                <option value="fixed"><Trans>Festbetrag</Trans></option>
                                <option value="percentage"><Trans>Prozent</Trans></option>
                                <option value="photo_package"><Trans>Foto-Paket</Trans></option>
                            </select>
                            {errors.type && (
                                <span className="text-error text-xs mt-1">{errors.type.message}</span>
                            )}
                        </div>

                        {!showPackageFields && (
                            <div className="form-control">
                                <label className="label">
                                    <span className="label-text font-bold">{valueLabel}</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    {...register('value', { setValueAs: toOptionalNumber })}
                                    className={`input input-bordered font-mono ${errors.value ? 'input-error' : ''}`}
                                />
                                {errors.value && (
                                    <span className="text-error text-xs mt-1">{errors.value.message}</span>
                                )}
                            </div>
                        )}

                        {showPackageFields && (
                            <>
                                <div className="form-control">
                                    <label className="label">
                                        <span className="label-text font-bold"><Trans>Anzahl Fotos (N)</Trans></span>
                                    </label>
                                    <input
                                        type="number"
                                        step="1"
                                        min="1"
                                        required
                                        {...register('package_quantity', { setValueAs: toOptionalNumber })}
                                        className={`input input-bordered font-mono ${errors.package_quantity ? 'input-error' : ''}`}
                                        placeholder={t`z.B. 10`}
                                    />
                                    {errors.package_quantity && (
                                        <span className="text-error text-xs mt-1">{errors.package_quantity.message}</span>
                                    )}
                                </div>

                                <div className="form-control">
                                    <label className="label">
                                        <span className="label-text font-bold"><Trans>Festpreis in € (Y)</Trans></span>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        required
                                        {...register('package_price_cents', { setValueAs: toOptionalNumber })}
                                        className={`input input-bordered font-mono ${errors.package_price_cents ? 'input-error' : ''}`}
                                        placeholder={t`z.B. 40`}
                                    />
                                    {errors.package_price_cents && (
                                        <span className="text-error text-xs mt-1">{errors.package_price_cents.message}</span>
                                    )}
                                </div>
                            </>
                        )}

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Geltungsbereich</Trans></span>
                            </label>
                            <select required {...register('scope_type')} className="select select-bordered">
                                <option value="global"><Trans>Global</Trans></option>
                                <option value="gallery"><Trans>Galerie</Trans></option>
                                <option value="meta_gallery"><Trans>Galerie-Gruppe</Trans></option>
                                <option value="organisation"><Trans>Organisation</Trans></option>
                                <option value="photographer"><Trans>Fotograf</Trans></option>
                            </select>
                            {errors.scope_type && (
                                <span className="text-error text-xs mt-1">{errors.scope_type.message}</span>
                            )}
                        </div>

                        {showScopeTarget && (
                            <div className="form-control md:col-span-2">
                                <label className="label">
                                    <span className="label-text font-bold"><Trans>Ziel-ID</Trans></span>
                                </label>
                                <input
                                    type="text"
                                    {...register('scope_id')}
                                    className={`input input-bordered font-mono ${errors.scope_id ? 'input-error' : ''}`}
                                    placeholder={watchScopeType === 'gallery' ? t`Galerie-ID` : t`Galerie-Gruppen-ID`}
                                />
                                {errors.scope_id && (
                                    <span className="text-error text-xs mt-1">{errors.scope_id.message}</span>
                                )}
                            </div>
                        )}

                        {showMaxItems && (
                            <div className="form-control md:col-span-2">
                                <label className="label">
                                    <span className="label-text font-bold"><Trans>Auf X günstigste Bilder beschränken</Trans></span>
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    max="999"
                                    step="1"
                                    {...register('max_items', { setValueAs: toOptionalNumber })}
                                    className={`input input-bordered font-mono ${errors.max_items ? 'input-error' : ''}`}
                                    placeholder={t`leer = auf gesamten Warenkorb`}
                                />
                                {errors.max_items && (
                                    <span className="text-error text-xs mt-1">{errors.max_items.message}</span>
                                )}
                            </div>
                        )}

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Max. globale Verwendungen</Trans></span>
                            </label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                {...register('max_uses_global', { setValueAs: toOptionalNumber })}
                                className={`input input-bordered font-mono ${errors.max_uses_global ? 'input-error' : ''}`}
                                placeholder={t`unbegrenzt`}
                            />
                            {errors.max_uses_global && (
                                <span className="text-error text-xs mt-1">{errors.max_uses_global.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Max. Verwendungen pro Account</Trans></span>
                            </label>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                {...register('max_uses_per_account', { setValueAs: toOptionalNumber })}
                                className={`input input-bordered font-mono ${errors.max_uses_per_account ? 'input-error' : ''}`}
                                placeholder={t`unbegrenzt`}
                            />
                            {errors.max_uses_per_account && (
                                <span className="text-error text-xs mt-1">{errors.max_uses_per_account.message}</span>
                            )}
                        </div>

                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold"><Trans>Gültig bis</Trans></span>
                            </label>
                            <input
                                type="date"
                                {...register('expires_at')}
                                className="input input-bordered"
                            />
                        </div>

                        <div className="form-control">
                            <label className="label cursor-pointer justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                <input
                                    type="checkbox"
                                    {...register('active')}
                                    className="checkbox checkbox-primary"
                                />
                                <span className="label-text font-bold"><Trans>Aktiv</Trans></span>
                                <span className="label-text-alt opacity-60">
                                    {watchActive ? <Trans>Gutscheincodes können eingelöst werden</Trans> : <Trans>Gutscheincodes sind deaktiviert</Trans>}
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
        </ModalDialogShell>
    );
}
