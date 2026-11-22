import { useState } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import { createEurEntry, type EurEntriesResponse, type EurReference } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

const MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
];

interface Props {
    data?: EurEntriesResponse;
    reference?: EurReference;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
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

export default function EurEntriesPanel({data, reference, isLoading, error, onRetry, onCreated}: Props) {
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

    const submit = async () => {
        if (!isValid || isSaving) return;

        setIsSaving(true);
        setSaveError(null);

        const kind = kinds.find((candidate) => candidate.type === form.kindType);

        try {
            await createEurEntry({
                year: 2026,
                month: Number(form.month),
                direction: form.direction,
                kind_type: form.kindType,
                e1a_code: kind?.e1a ?? null,
                business_share: share,
                amount_cents: amountCents,
                receipt_no: form.receiptNo === '' ? null : form.receiptNo,
            });

            setForm({...INITIAL, direction: form.direction});
            onCreated();
        } catch {
            setSaveError('Die Buchung konnte nicht gespeichert werden. Prüfen Sie die Pflichtfelder und den Betriebsanteil (5-%-Schritte).');
        } finally {
            setIsSaving(false);
        }
    };

    if (isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Buchungen werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (error || !data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Buchungen nicht verfügbar</Trans></h2>
                    <p><Trans>Die Buchungen konnten nicht geladen werden.</Trans></p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={onRetry}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div id="eur-buchungen" className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Buchungen</Trans></h2>

                {data.entries.length === 0 ? (
                    <p className="opacity-70"><Trans>Noch keine Buchungen erfasst. Legen Sie unten die erste Buchung an.</Trans></p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Monat</Trans></th>
                                    <th><Trans>Art</Trans></th>
                                    <th className="text-right"><Trans>Betrag</Trans></th>
                                    <th className="text-right"><Trans>Anteil</Trans></th>
                                    <th className="text-right"><Trans>Betrieblich</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td>{MONTHS[entry.month - 1]}</td>
                                        <td>
                                            <span className={`badge ${entry.direction === 'income' ? 'badge-success' : 'badge-error'} badge-outline mr-2`}>
                                                {entry.direction === 'income' ? <Trans>Einnahme</Trans> : <Trans>Ausgabe</Trans>}
                                            </span>
                                            {entry.kind_type}
                                        </td>
                                        <td className="text-right">{cents(entry.amount_cents)}</td>
                                        <td className="text-right">{entry.business_share} %</td>
                                        <td className="text-right">{cents(entry.business_amount_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Neue Buchung</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.month}
                            onChange={(event) => update('month', event.target.value)}
                            required
                        >
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Richtung</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.direction}
                            onChange={(event) => update('direction', event.target.value as 'income' | 'expense')}
                            required
                        >
                            <option value="expense"><Trans>Ausgabe</Trans></option>
                            <option value="income"><Trans>Einnahme</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Art</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.kindType}
                            onChange={(event) => update('kindType', event.target.value)}
                            required
                        >
                            <option value="">—</option>
                            {kinds.map((kind) => (
                                <option key={kind.type} value={kind.type}>{kind.type}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betrag (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.amountEuro}
                            onChange={(event) => update('amountEuro', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil (%)</Trans></span>
                        <input
                            type="number"
                            min={0}
                            max={100}
                            step={5}
                            className="input input-bordered"
                            value={form.businessShare}
                            onChange={(event) => update('businessShare', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Belegnummer</Trans></span>
                        <input
                            type="text"
                            className="input input-bordered"
                            value={form.receiptNo}
                            onChange={(event) => update('receiptNo', event.target.value)}
                        />
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert">
                        <span>{saveError}</span>
                    </div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isSaving}>
                        <Trans>Buchung speichern</Trans>
                    </button>
                    {!isValid && (
                        <p className="text-sm opacity-70 mt-2">
                            <Trans>Art auswählen und Betrag sowie Betriebsanteil in 5-%-Schritten angeben.</Trans>
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
