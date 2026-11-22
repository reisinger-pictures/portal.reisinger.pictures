import { useState } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import { calculateEurVat, EUR_MONTHS as MONTHS, type EurVatKz, type EurVatResult } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

const KZ_LABELS: {key: EurVatKz; label: string}[] = [
    {key: '000', label: 'Gesamtbetrag'},
    {key: '016', label: 'Kleinunternehmer-Umsatz'},
    {key: '057', label: 'Reverse Charge'},
    {key: '070', label: 'Innergemeinschaftliche Erwerbe gesamt'},
    {key: '072', label: 'IG-Erwerb 20 %'},
    {key: '073', label: 'IG-Erwerb 10 %'},
    {key: '008', label: 'IG-Erwerb 13 %'},
    {key: '125', label: 'IG-Erwerb 4,9 %'},
    {key: 'zahllast', label: 'Zahllast'},
];

interface FormState {
    month: string;
    incomeEuro: string;
    reverseEuro: string;
    base20: string;
    base10: string;
    base13: string;
    base49: string;
}

const INITIAL: FormState = {
    month: '1',
    incomeEuro: '',
    reverseEuro: '',
    base20: '',
    base10: '',
    base13: '',
    base49: '',
};

const toCents = (value: string): number | null => {
    if (value.trim() === '') return null;
    const parsed = Number(value.replace(',', '.'));
    return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 100) : null;
};

export default function EurVatPanel() {
    const [form, setForm] = useState<FormState>(INITIAL);
    const [result, setResult] = useState<EurVatResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (isLoading) return;

        setIsLoading(true);
        setError(null);

        try {
            const response = await calculateEurVat({
                year: 2026,
                month: Number(form.month),
                small_business_income_cents: toCents(form.incomeEuro),
                reverse_charge_cents: toCents(form.reverseEuro),
                ig_bases_cents: {
                    '20': toCents(form.base20),
                    '10': toCents(form.base10),
                    '13': toCents(form.base13),
                    '49': toCents(form.base49),
                },
            });
            setResult(response);
        } catch {
            setResult(null);
            setError('Die Umsatzsteuer konnte nicht berechnet werden. Prüfen Sie die Beträge und versuchen Sie es erneut.');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Umsatzsteuer</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Monatliche Kennzahlen und Zahllast. Leere Felder bleiben leer und werden nicht als Null ausgewiesen.</Trans>
                </p>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.month}
                            onChange={(event) => update('month', event.target.value)}
                        >
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Kleinunternehmer-Umsatz (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.incomeEuro}
                            onChange={(event) => update('incomeEuro', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Reverse Charge (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.reverseEuro}
                            onChange={(event) => update('reverseEuro', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>IG-Erwerb 20 % (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.base20}
                            onChange={(event) => update('base20', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>IG-Erwerb 10 % (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.base10}
                            onChange={(event) => update('base10', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>IG-Erwerb 13 % (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.base13}
                            onChange={(event) => update('base13', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>IG-Erwerb 4,9 % (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.base49}
                            onChange={(event) => update('base49', event.target.value)}
                        />
                    </label>
                </div>

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={isLoading}>
                        <Trans>Kennzahlen berechnen</Trans>
                    </button>
                </div>

                {error && (
                    <div className="alert alert-error mt-4" role="alert">
                        <span>{error}</span>
                    </div>
                )}

                {result && (
                    <div className="mt-6 overflow-x-auto">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Kennzahl</Trans></th>
                                    <th><Trans>Bedeutung</Trans></th>
                                    <th className="text-right"><Trans>Betrag</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {KZ_LABELS.map((entry) => {
                                    const value = result.kz[entry.key];
                                    return (
                                        <tr key={entry.key}>
                                            <td className="font-mono">{entry.key === 'zahllast' ? '—' : `KZ ${entry.key}`}</td>
                                            <td>{entry.label}</td>
                                            <td className="text-right">{value === null ? '—' : cents(value)}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
