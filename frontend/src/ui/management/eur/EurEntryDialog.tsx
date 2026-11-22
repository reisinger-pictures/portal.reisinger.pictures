import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { createEurEntry, EUR_MONTHS as MONTHS, type EurReference } from '../../../logic/useEur';
import { useFocusTrap } from '../../../logic/useFocusTrap';

interface Props {
    year: number;
    reference?: EurReference;
    onClose: () => void;
    onCreated: () => void;
}

interface FormState {
    month: string;
    direction: 'income' | 'expense';
    kindType: string;
    amountEuro: string;
    businessShare: string;
    receiptNo: string;
}

const INITIAL: FormState = {
    month: '1',
    direction: 'expense',
    kindType: '',
    amountEuro: '',
    businessShare: '100',
    receiptNo: '',
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
    const [form, setForm] = useState<FormState>(INITIAL);
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const kinds = form.direction === 'income'
        ? (reference?.income_kinds ?? [])
        : (reference?.expense_kinds ?? []);

    const amountCents = Math.round(Number(form.amountEuro.replace(',', '.')) * 100);
    const share = Number(form.businessShare);
    const isValid = Number.isFinite(amountCents) && amountCents >= 0
        && Number.isInteger(share) && share >= 0 && share <= 100 && share % 5 === 0
        && Number(form.month) >= 1 && Number(form.month) <= 12
        && form.kindType !== '';

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    // EUE-033/048: die Art aus dem Referenzartefakt bestimmt den Betriebsanteil
    // (z. B. 0 % für private Posten). Der Wert bleibt überschreibbar.
    const selectKind = (type: string) => {
        const kind = kinds.find((candidate) => candidate.type === type);
        const share = kind?.share_percent;

        setForm((current) => ({
            ...current,
            kindType: type,
            businessShare: share === null || share === undefined ? current.businessShare : String(share),
        }));
    };

    const selectDirection = (direction: 'income' | 'expense') => {
        setForm((current) => ({...current, direction, kindType: '', businessShare: '100'}));
    };

    const submit = async () => {
        if (!isValid || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        const kind = kinds.find((candidate) => candidate.type === form.kindType);

        try {
            await createEurEntry({
                year,
                month: Number(form.month),
                direction: form.direction,
                kind_type: form.kindType,
                // EUE-033/048: bei 0 % ist die Buchung privat und erhält keine KZ.
                e1a_code: share === 0 ? null : kzFromE1a(kind?.e1a),
                business_share: share,
                amount_cents: amountCents,
                receipt_no: form.receiptNo === '' ? null : form.receiptNo,
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
                        <select className="select select-bordered" value={form.month}
                            onChange={(event) => update('month', event.target.value)} required>
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Richtung</Trans></span>
                        <select className="select select-bordered" value={form.direction}
                            onChange={(event) => selectDirection(event.target.value as 'income' | 'expense')} required>
                            <option value="expense"><Trans>Ausgabe</Trans></option>
                            <option value="income"><Trans>Einnahme</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Art</Trans></span>
                        <select className="select select-bordered" value={form.kindType}
                            onChange={(event) => selectKind(event.target.value)} required>
                            <option value="">—</option>
                            {kinds.map((kind) => (
                                <option key={kind.type} value={kind.type}>{kind.type}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betrag (€)</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered"
                            value={form.amountEuro} onChange={(event) => update('amountEuro', event.target.value)} required/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil (%)</Trans></span>
                        <input type="number" min={0} max={100} step={5} className="input input-bordered"
                            value={form.businessShare} onChange={(event) => update('businessShare', event.target.value)} required/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Belegnummer</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.receiptNo} onChange={(event) => update('receiptNo', event.target.value)}/>
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
                    <button type="button" className="btn btn-primary" onClick={submit} disabled={!isValid || isSaving}>
                        <Trans>Buchung speichern</Trans>
                    </button>
                </div>
            </div>
        </div>
    );
}
