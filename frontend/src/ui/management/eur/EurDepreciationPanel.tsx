import { useState } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import { EUR_MONTHS as MONTHS, fetchEurComparison, type EurAssetComparison } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface FormState {
    costEuro: string;
    usefulLife: string;
    ratePercent: string;
    acquisitionMonth: string;
}

const INITIAL: FormState = {
    costEuro: '20000',
    usefulLife: '10',
    ratePercent: '30',
    acquisitionMonth: '1',
};

export default function EurDepreciationPanel() {
    const [form, setForm] = useState<FormState>(INITIAL);
    const [result, setResult] = useState<EurAssetComparison | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    const costCents = Math.round(Number(form.costEuro.replace(',', '.')) * 100);
    const usefulLife = Number(form.usefulLife);
    const rate = Math.round(Number(form.ratePercent.replace(',', '.')) * 100);
    const month = Number(form.acquisitionMonth);

    const isValid = Number.isFinite(costCents) && costCents > 0
        && Number.isInteger(usefulLife) && usefulLife >= 2 && usefulLife <= 99
        && Number.isInteger(rate) && rate >= 100 && rate <= 3000
        && month >= 1 && month <= 12;

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (!isValid || isLoading) return;

        setIsLoading(true);
        setError(null);

        try {
            const comparison = await fetchEurComparison({
                cost_cents: costCents,
                useful_life_years: usefulLife,
                degressive_rate: rate,
                acquisition_month: month,
            });
            setResult(comparison);
        } catch {
            setResult(null);
            setError('Der Vergleich konnte nicht berechnet werden. Prüfen Sie die Eingaben und versuchen Sie es erneut.');
        } finally {
            setIsLoading(false);
        }
    };

    const switchYear = result?.switch_recommended_year ?? null;
    const switchAdvantage = result ? cents(result.switch_advantage_cents) : '';

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Abschreibung vergleichen</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Vergleicht die lineare und die degressive Abschreibung für ein Anlagegut. Der Wechsel zur linearen Methode ist einmalig und nur mit Begründung möglich.</Trans>
                </p>

                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Anschaffungskosten (€)</Trans></span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="input input-bordered"
                            value={form.costEuro}
                            onChange={(event) => update('costEuro', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Nutzungsdauer (Jahre)</Trans></span>
                        <input
                            type="number"
                            min={2}
                            max={99}
                            className="input input-bordered"
                            value={form.usefulLife}
                            onChange={(event) => update('usefulLife', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Degressiver Satz (%)</Trans></span>
                        <input
                            type="number"
                            min={1}
                            max={30}
                            className="input input-bordered"
                            value={form.ratePercent}
                            onChange={(event) => update('ratePercent', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Anschaffungsmonat</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.acquisitionMonth}
                            onChange={(event) => update('acquisitionMonth', event.target.value)}
                            required
                        >
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isLoading}>
                        <Trans>Vergleich berechnen</Trans>
                    </button>
                </div>

                {error && (
                    <div className="alert alert-error mt-4" role="alert">
                        <span>{error}</span>
                    </div>
                )}

                {result && (
                    <div className="mt-6">
                        {result.switch_recommended_year !== null ? (
                            <p className="mb-3">
                                <Trans>Empfohlener Wechsel zur linearen Abschreibung: Jahr {switchYear}. Vorteil gegenüber der durchgehenden degressiven Methode: {switchAdvantage}.</Trans>
                            </p>
                        ) : (
                            <p className="mb-3"><Trans>Für diese Angaben ist kein Wechsel zur linearen Methode vorteilhaft.</Trans></p>
                        )}

                        <div className="overflow-x-auto">
                            <table className="table table-sm">
                                <thead>
                                    <tr>
                                        <th><Trans>Jahr</Trans></th>
                                        <th className="text-right"><Trans>Linear</Trans></th>
                                        <th className="text-right"><Trans>Degressiv</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.linear.map((row, index) => (
                                        <tr key={row.year}>
                                            <td>{row.year}</td>
                                            <td className="text-right">{cents(row.depreciation_cents)}</td>
                                            <td className="text-right">{cents(result.degressive[index]?.depreciation_cents ?? 0)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
