import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { createEaInvoiceCorrection } from '../../../logic/useEa';

interface Props {
    year: number;
    month: number;
    onCreated: () => void;
}

/** Betrag in ganze Cent, deutsches Komma erlaubt; null ist ein Zeilenfehler. */
const refundCentsOrNull = (value: string): number | null => {
    if (value.trim() === '') return null;
    const euro = Number(value.replace(',', '.'));
    if (!Number.isFinite(euro) || euro <= 0) return null;
    const cents = Math.round(euro * 100);
    return Number.isFinite(cents) && cents >= 1 ? cents : null;
};

const integerInRangeOrNull = (value: string, min: number, max: number): number | null => {
    if (value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isInteger(parsed) && parsed >= min && parsed <= max ? parsed : null;
};

// Schema-Factory im Komponenten-Body: ein `t`-Makro auf Modulebene würde vor
// i18n.activate() ausgewertet werden und abstürzen (siehe main.tsx).
const createCorrectionSchema = () => z.object({
    invoiceNumber: z.string().trim().min(1, t`Rechnungsnummer angeben`),
    refundEuro: z.string().refine((value) => refundCentsOrNull(value) !== null,
        t`Bitte einen gültigen Rückzahlungsbetrag über null angeben`),
    reason: z.string().trim().min(1, t`Grund angeben`),
    year: z.string().refine((value) => integerInRangeOrNull(value, 2024, 2026) !== null,
        t`Jahr zwischen 2024 und 2026 wählen`),
    month: z.string().refine((value) => integerInRangeOrNull(value, 1, 12) !== null,
        t`Monat zwischen 1 und 12 wählen`),
    day: z.string().refine((value) => integerInRangeOrNull(value, 1, 31) !== null,
        t`Tag zwischen 1 und 31 wählen`),
});

type CorrectionFormValues = z.infer<ReturnType<typeof createCorrectionSchema>>;

export default function EaInvoiceCorrectionPanel({year, month, onCreated}: Props) {
    const [isSaving, setIsSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const defaults: CorrectionFormValues = {
        invoiceNumber: '',
        refundEuro: '',
        reason: '',
        year: String(year),
        month: String(month),
        day: String(new Date().getDate()),
    };
    const {register, handleSubmit, reset, formState: {errors}} = useForm<CorrectionFormValues>({
        resolver: zodResolver(createCorrectionSchema()),
        defaultValues: defaults,
        mode: 'onTouched',
    });

    const submit = async (values: CorrectionFormValues) => {
        const refundCents = refundCentsOrNull(values.refundEuro);
        if (refundCents === null || isSaving) return;

        setIsSaving(true);
        setMessage(null);
        setError(null);

        try {
            await createEaInvoiceCorrection({
                invoice_number: values.invoiceNumber.trim(),
                refund_cents: refundCents,
                reason: values.reason.trim(),
                year: Number(values.year),
                month: Number(values.month),
                day: Number(values.day),
            });
            setMessage(t`Die Rechnungskorrektur wurde gebucht.`);
            reset(defaults);
            onCreated();
        } catch (cause) {
            setError(cause instanceof Error && cause.message.trim() !== ''
                ? cause.message
                : t`Die Rechnungskorrektur konnte nicht gebucht werden. Bitte erneut versuchen.`);
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <section data-testid="ea-invoice-correction-panel" className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Rechnungskorrektur</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Eine extern bereits erstattete Teilrückzahlung wird als negative Einnahme (Erlösminderung) gebucht. Es wird keine Zahlung ausgelöst; die Umsatzsteuer-Korrektur bleibt außerhalb dieser Buchung.</Trans>
                </p>

                <form onSubmit={handleSubmit(submit)} className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4" noValidate>
                    <label className="form-control">
                        <span className="label-text"><Trans>Rechnungsnummer</Trans></span>
                        <input type="text" className="input input-bordered" placeholder={t`P-2026-0001-0001`}
                            {...register('invoiceNumber')} required/>
                        {errors.invoiceNumber && <span className="text-error text-xs mt-1">{errors.invoiceNumber.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Rückzahlung (€)</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered" placeholder={t`70,00`}
                            {...register('refundEuro')} required/>
                        {errors.refundEuro && <span className="text-error text-xs mt-1">{errors.refundEuro.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Grund</Trans></span>
                        <input type="text" className="input input-bordered" {...register('reason')} required/>
                        {errors.reason && <span className="text-error text-xs mt-1">{errors.reason.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Jahr</Trans></span>
                        <input type="number" min={2024} max={2026} step={1} className="input input-bordered"
                            {...register('year')} required/>
                        {errors.year && <span className="text-error text-xs mt-1">{errors.year.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat</Trans></span>
                        <input type="number" min={1} max={12} step={1} className="input input-bordered"
                            {...register('month')} required/>
                        {errors.month && <span className="text-error text-xs mt-1">{errors.month.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Tag</Trans></span>
                        <input type="number" min={1} max={31} step={1} className="input input-bordered"
                            {...register('day')} required/>
                        {errors.day && <span className="text-error text-xs mt-1">{errors.day.message}</span>}
                    </label>
                    <div className="md:col-span-3">
                        <button type="submit" className="btn btn-outline btn-sm"><Trans>Rechnungskorrektur buchen</Trans></button>
                    </div>
                </form>

                {message && <p className="alert alert-success text-sm mt-4" role="status">{message}</p>}
                {error && <p className="alert alert-error text-sm mt-4" role="alert">{error}</p>}
            </div>
        </section>
    );
}
