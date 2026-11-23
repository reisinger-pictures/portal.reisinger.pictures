import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    createEurWarehouse,
    useEurReference,
    useEurWarehouse,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    year: number;
    month: number;
}

interface FormState {
    day: string;
    description: string;
    supplier: string;
    receiptNo: string;
    expenseKind: string;
    amountEuro: string;
}

const INITIAL: FormState = {
    day: '', description: '', supplier: '', receiptNo: '', expenseKind: '', amountEuro: '',
};

export default function EurWarehousePanel({year, month}: Props) {
    const warehouse = useEurWarehouse(year, month);
    const reference = useEurReference();
    const [form, setForm] = useState<FormState>(INITIAL);
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const amountCents = Math.round(Number(form.amountEuro.replace(',', '.')) * 100);
    const day = Number(form.day);
    const receiptNo = form.receiptNo.trim();
    const isWeReceipt = /^WE/i.test(receiptNo);
    const expenseKinds = reference.data?.expense_kinds ?? [];

    const isValid = form.description.trim() !== ''
        && form.supplier.trim() !== ''
        && receiptNo !== ''
        && (!isWeReceipt || form.expenseKind !== '')
        && Number.isFinite(amountCents) && amountCents > 0
        && Number.isInteger(day) && day >= 1 && day <= 31;

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (!isValid || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            await createEurWarehouse({
                year,
                month,
                day,
                description: form.description.trim(),
                supplier: form.supplier.trim(),
                expense_kind: form.expenseKind === '' ? null : form.expenseKind,
                amount_cents: amountCents,
                receipt_no: receiptNo,
            });
            setForm(INITIAL);
            void warehouse.mutate();
        } catch {
            setSaveError(t`Der Wareneingang konnte nicht gespeichert werden. Prüfen Sie Pflichtfelder, Belegnummer und Ausgaben-Art und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    if (warehouse.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Wareneingang wird geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (warehouse.error || !warehouse.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Wareneingang nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Der Wareneingang konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie die Liste erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void warehouse.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const entries = warehouse.data.entries;
    const warehouseTotal = cents(warehouse.data.total_cents);

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Wareneingang</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Erfassen Sie Wareneinkäufe mit Belegnummer. Der Betrag wird im selben Monat beim Abgleich berücksichtigt.</Trans>
                </p>

                {entries.length === 0 ? (
                    <p className="opacity-70 mt-4">
                        <Trans>Noch keine Wareneingänge erfasst. Legen Sie unten den ersten Eintrag an.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Tag</Trans></th>
                                    <th><Trans>Beschreibung</Trans></th>
                                    <th><Trans>Lieferant</Trans></th>
                                    <th><Trans>Ausgaben-Art</Trans></th>
                                    <th><Trans>Beleg</Trans></th>
                                    <th className="text-right"><Trans>Betrag</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td>{entry.day ?? '—'}</td>
                                        <td>{entry.description}</td>
                                        <td>{entry.supplier ?? '—'}</td>
                                        <td>{entry.expense_kind ?? '—'}</td>
                                        <td>{entry.receipt_no ?? '—'}</td>
                                        <td className="text-right">{cents(entry.amount_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="text-sm mt-2">
                            <Trans>Summe Wareneingang: {warehouseTotal}</Trans>
                        </p>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Neuer Wareneingang</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Tag</Trans></span>
                        <input type="number" min={1} max={31} className="input input-bordered"
                            value={form.day} onChange={(event) => update('day', event.target.value)} required/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betrag (€)</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered"
                            value={form.amountEuro} onChange={(event) => update('amountEuro', event.target.value)} required/>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Beschreibung</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.description} onChange={(event) => update('description', event.target.value)} required/>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Lieferant</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.supplier} onChange={(event) => update('supplier', event.target.value)} required/>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Belegnummer</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.receiptNo} onChange={(event) => update('receiptNo', event.target.value)} required/>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Ausgaben-Art</Trans></span>
                        <select className="select select-bordered" value={form.expenseKind}
                            onChange={(event) => update('expenseKind', event.target.value)}
                            required={isWeReceipt}>
                            <option value="">—</option>
                            {expenseKinds.map((kind) => (
                                <option key={kind.type} value={kind.type}>{kind.type}</option>
                            ))}
                        </select>
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isSaving}>
                        <Trans>Wareneingang speichern</Trans>
                    </button>
                    {!isValid && (
                        <p className="text-sm opacity-70 mt-2">
                            <Trans>Tag, Beschreibung, Lieferant, Belegnummer und Betrag angeben; bei WE-Belegen ist die Ausgaben-Art erforderlich.</Trans>
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
