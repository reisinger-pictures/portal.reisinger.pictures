import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { createEurEntry, EUR_MONTHS as MONTHS, type EurReference } from '../../../logic/useEur';
import { useFocusTrap } from '../../../logic/useFocusTrap';

interface Props {
    year: number;
    reference?: EurReference;
    onClose: () => void;
    onCreated: () => void;
}

/** Mirrors the previous `Math.round(Number(value.replace(',', '.')) * 100)` plus its guards. */
const amountCentsOrNull = (value: string): number | null => {
    if (value.trim() === '') return null;
    const amount = Number(value.replace(',', '.'));
    if (!Number.isFinite(amount)) return null;
    const cents = Math.round(amount * 100);
    return Number.isFinite(cents) && cents >= 0 ? cents : null;
};

const isBusinessShare = (value: string): boolean => {
    if (value.trim() === '') return false;
    const share = Number(value);
    return Number.isInteger(share) && share >= 0 && share <= 100 && share % 5 === 0;
};

// Built by a factory called inside the component: a `t` macro evaluated at
// module scope would run before i18n.activate() and crash (see main.tsx).
const createEurEntrySchema = () => z.object({
    month: z.string().refine((value) => {
        const month = Number(value);
        return value.trim() !== '' && Number.isInteger(month) && month >= 1 && month <= 12;
    }, t`Monat zwischen 1 und 12 wählen`),
    direction: z.enum(['income', 'expense']),
    kindType: z.string().min(1, t`Bitte eine Art auswählen`),
    amountEuro: z.string().refine((value) => amountCentsOrNull(value) !== null,
        t`Bitte einen gültigen Betrag angeben`),
    businessShare: z.string().refine(isBusinessShare,
        t`Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben`),
    receiptNo: z.string(),
    // EUE-005/006: optionale Zuordnung, '' bedeutet "nicht gesetzt".
    paymentMethod: z.string(),
    projectId: z.string(),
    // EUE-039/040: optionale USt-Behandlung aus dem gepinnten Katalog.
    vatKind: z.string(),
});

type EurEntryFormValues = z.infer<ReturnType<typeof createEurEntrySchema>>;

const INITIAL: EurEntryFormValues = {
    month: '1',
    direction: 'expense',
    kindType: '',
    amountEuro: '',
    businessShare: '100',
    receiptNo: '',
    paymentMethod: '',
    projectId: '',
    vatKind: '',
};

// The reference table stores descriptive labels such as
// "9190 - Provisionen und Lizenzen", but `e1a_code` only accepts up to 8
// characters. Keep the leading KZ digits, mirroring EurAssetController.
const kzFromE1a = (e1a: string | null | undefined): string | null => {
    const match = e1a?.match(/^\d+/);
    return match ? match[0] : null;
};

export default function EurEntryDialog({year, reference, onClose, onCreated}: Props) {
    const dialogRef = useFocusTrap<HTMLDivElement>(true, {onEscape: onClose});
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const eurEntrySchema = createEurEntrySchema();
    const {register, control, setValue, handleSubmit, formState: {errors, isValid}} = useForm<EurEntryFormValues>({
        resolver: zodResolver(eurEntrySchema),
        defaultValues: INITIAL,
        // `onTouched` keeps isValid live for the disabled submit button without
        // firing validation on the very first keystroke.
        mode: 'onTouched',
    });

    const direction = useWatch({control, name: 'direction'});
    const kinds = direction === 'income'
        ? (reference?.income_kinds ?? [])
        : (reference?.expense_kinds ?? []);

    // EUE-033/048: die Art aus dem Referenzartefakt bestimmt den Betriebsanteil
    // (z. B. 0 % für private Posten). Der Wert bleibt überschreibbar.
    const selectKind = (type: string) => {
        const kind = kinds.find((candidate) => candidate.type === type);
        const share = kind?.share_percent;

        setValue('kindType', type, {shouldValidate: true});
        if (share !== null && share !== undefined) {
            setValue('businessShare', String(share), {shouldValidate: true});
        }
    };

    const selectDirection = (next: 'income' | 'expense') => {
        setValue('direction', next, {shouldValidate: true});
        setValue('kindType', '', {shouldValidate: true});
        setValue('businessShare', '100', {shouldValidate: true});
    };

    const submit = async (values: EurEntryFormValues) => {
        if (isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        const amountCents = Math.round(Number(values.amountEuro.replace(',', '.')) * 100);
        const share = Number(values.businessShare);
        const kind = kinds.find((candidate) => candidate.type === values.kindType);

        try {
            await createEurEntry({
                year,
                month: Number(values.month),
                direction: values.direction,
                kind_type: values.kindType,
                // EUE-033/048: bei 0 % ist die Buchung privat und erhält keine KZ.
                e1a_code: share === 0 ? null : kzFromE1a(kind?.e1a),
                business_share: share,
                amount_cents: amountCents,
                receipt_no: values.receiptNo === '' ? null : values.receiptNo,
                payment_method: values.paymentMethod === '' ? null : values.paymentMethod,
                eur_project_id: values.projectId === '' ? null : Number(values.projectId),
                vat_kind: values.vatKind === '' ? null : values.vatKind,
            });
            onCreated();
            onClose();
        } catch {
            setSaveError(t`Die Buchung konnte nicht gespeichert werden. Prüfen Sie die Pflichtfelder und den Betriebsanteil in 5-%-Schritten.`);
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="eur-entry-dialog-title"
                 className="w-full max-w-2xl rounded-box bg-base-100 p-6 shadow-xl">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="eur-entry-dialog-title" className="text-xl font-semibold"><Trans>Neue Buchung</Trans></h2>
                        <p className="text-sm opacity-70"><Trans>Erfassen Sie Einnahmen und Ausgaben mit Betriebsanteil und Belegnummer.</Trans></p>
                    </div>
                    <button type="button" className="btn btn-ghost btn-sm" onClick={onClose} aria-label={t`Schließen`}>
                        <Trans>Schließen</Trans>
                    </button>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat</Trans></span>
                        <select className="select select-bordered" {...register('month')} required>
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                        {errors.month && <span className="text-error text-xs mt-1">{errors.month.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Richtung</Trans></span>
                        <select className="select select-bordered" {...register('direction')}
                            onChange={(event) => selectDirection(event.target.value as 'income' | 'expense')} required>
                            <option value="expense"><Trans>Ausgabe</Trans></option>
                            <option value="income"><Trans>Einnahme</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Art</Trans></span>
                        <select className="select select-bordered" {...register('kindType')}
                            onChange={(event) => selectKind(event.target.value)} required>
                            <option value="">—</option>
                            {kinds.map((kind) => (
                                <option key={kind.type} value={kind.type}>{kind.type}</option>
                            ))}
                        </select>
                        {errors.kindType && <span className="text-error text-xs mt-1">{errors.kindType.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betrag (€)</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered"
                            {...register('amountEuro')} required/>
                        {errors.amountEuro && <span className="text-error text-xs mt-1">{errors.amountEuro.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil (%)</Trans></span>
                        <input type="number" min={0} max={100} step={5} className="input input-bordered"
                            {...register('businessShare')} required/>
                        {errors.businessShare && <span className="text-error text-xs mt-1">{errors.businessShare.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Belegnummer</Trans></span>
                        <input type="text" className="input input-bordered" {...register('receiptNo')}/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Zahlungsart</Trans></span>
                        <select className="select select-bordered" {...register('paymentMethod')}>
                            <option value="">—</option>
                            {(reference?.payment_methods ?? []).map((method) => (
                                <option key={method.kind} value={method.kind}>{method.kind}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Projekt</Trans></span>
                        <select className="select select-bordered" {...register('projectId')}>
                            <option value="">—</option>
                            {(reference?.eur_projects ?? []).map((project) => (
                                <option key={project.id} value={project.id}>{project.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>USt-Behandlung</Trans></span>
                        <select className="select select-bordered" {...register('vatKind')}>
                            <option value="">—</option>
                            {(reference?.vat_kinds ?? []).map((kind) => (
                                <option key={kind} value={kind}>{kind}</option>
                            ))}
                        </select>
                    </label>
                </div>

                {!isValid && (
                    <p className="text-sm opacity-70 mt-4">
                        <Trans>Art auswählen und Betrag sowie Betriebsanteil in 5-%-Schritten angeben.</Trans>
                    </p>
                )}
                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="flex justify-end gap-2 mt-6">
                    <button type="button" className="btn btn-outline" onClick={onClose}><Trans>Abbrechen</Trans></button>
                    <button type="button" className="btn btn-primary" onClick={handleSubmit(submit)} disabled={!isValid || isSaving}>
                        <Trans>Buchung speichern</Trans>
                    </button>
                </div>
            </div>
        </div>
    );
}
