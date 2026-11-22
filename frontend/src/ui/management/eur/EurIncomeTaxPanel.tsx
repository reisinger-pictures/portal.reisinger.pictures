import { useState } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import { calculateEurIncomeTax, type EurIncomeTaxResult } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    defaultProfitCents?: number;
}

export default function EurIncomeTaxPanel({defaultProfitCents = 0}: Props) {
    const [profitEuro, setProfitEuro] = useState(defaultProfitCents === 0 ? '' : String(defaultProfitCents / 100));
    const [result, setResult] = useState<EurIncomeTaxResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    const profitCents = Math.round(Number(profitEuro.replace(',', '.')) * 100);
    const isValid = Number.isFinite(profitCents) && profitCents >= 0;

    const submit = async () => {
        if (!isValid || isLoading) return;

        setIsLoading(true);
        setError(null);

        try {
            setResult(await calculateEurIncomeTax(profitCents));
        } catch {
            setResult(null);
            setError('Die Einkommensteuer konnte nicht berechnet werden. Prüfen Sie den Gewinn und versuchen Sie es erneut.');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="rounded-box border border-base-300 bg-base-100 p-6">
            <h2 className="text-xl font-semibold"><Trans>Einkommensteuer</Trans></h2>
            <p className="text-sm opacity-70">
                <Trans>Fünf Tarifstufen und Grundfreibetrag nach dem gepinnten Tarif 2026. Der Gewinn wird serverseitig versteuert.</Trans>
            </p>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 items-end">
                <label className="form-control md:col-span-2">
                    <span className="label-text"><Trans>Gewinn (€)</Trans></span>
                    <input
                        type="text"
                        inputMode="decimal"
                        className="input input-bordered"
                        value={profitEuro}
                        onChange={(event) => setProfitEuro(event.target.value)}
                        required
                    />
                </label>
                <button type="button" className="btn btn-primary" onClick={submit} disabled={!isValid || isLoading}>
                    <Trans>Steuer berechnen</Trans>
                </button>
            </div>

            {error && (
                <div className="alert alert-error mt-4" role="alert">
                    <span>{error}</span>
                </div>
            )}

            {result && (
                <>
                    <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Grundfreibetrag</Trans></dt>
                            <dd className="text-xl font-semibold">{cents(result.basic_allowance_cents)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Zu versteuernder Gewinn</Trans></dt>
                            <dd className="text-xl font-semibold">{cents(result.taxable_cents)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Einkommensteuer</Trans></dt>
                            <dd className="text-xl font-semibold text-primary">{cents(result.income_tax_cents)}</dd>
                        </div>
                    </dl>

                    <div className="overflow-x-auto mt-6">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Stufe</Trans></th>
                                    <th className="text-right"><Trans>Von</Trans></th>
                                    <th className="text-right"><Trans>Bis</Trans></th>
                                    <th className="text-right"><Trans>Satz</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.stages.map((stage, index) => (
                                    <tr key={`${stage.from_cents}-${stage.rate_per_10000}`}>
                                        <td>{index + 1}</td>
                                        <td className="text-right">{cents(stage.from_cents)}</td>
                                        <td className="text-right">{stage.to_cents === null ? 'offen' : cents(stage.to_cents)}</td>
                                        <td className="text-right">{formatPercent(stage.rate_per_10000 / 100)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </div>
    );
}
