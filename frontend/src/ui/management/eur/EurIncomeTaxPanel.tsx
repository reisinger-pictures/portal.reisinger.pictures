import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import {
    calculateEurIncomeTax,
    e1ByCodeRows,
    useEurHelpTexts,
    type EurE1ByCode,
    type EurIncomeTaxMode,
    type EurIncomeTaxResult,
} from '../../../logic/useEur';
import EurHelpPopover from './EurHelpPopover';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    defaultProfitCents?: number;
    /** E1A/E1B amounts keyed by code, supplied by the month endpoint when available. */
    e1ByCode?: EurE1ByCode;
}

// Die Kennzahlen ergeben sich aus der Portal-Logik, nicht aus dem Hilfetext.
const KZ_ERGONOMICS = '9216';
const KZ_WORKPLACE = '9215 / 9217';

/** Known KZ codes get a descriptive German label; unknown codes stay readable. */
function kzLabel(code: string): string {
    switch (code) {
        case 'E39': return t`Kennzahl E39 (Ergonomisches Mobiliar)`;
        case 'E40': return t`Kennzahl E40 (Arbeitsplatzpauschale)`;
        default: return t`Kennzahl ${code}`;
    }
}

/** Known Steuerbilanz keys are catalogued; unexpected keys remain visible verbatim. */
function steuerbilanzLabel(key: string): string {
    switch (key) {
        case 'betriebliche_einnahmen_cents':
        case 'betriebseinnahmen':
        case 'betriebseinnahmen_cents':
            return t`Betriebliche Einnahmen`;
        case 'betriebliche_ausgaben_cents':
        case 'betriebsausgaben':
        case 'betriebsausgaben_cents':
            return t`Betriebliche Ausgaben`;
        case 'gewinn':
        case 'gewinn_cents':
            return t`Gewinn`;
        case 'steuerlicher_gewinn_cents':
        case 'steuerpflichtiger_gewinn':
        case 'steuerpflichtiger_gewinn_cents':
            return t`Steuerlicher Gewinn`;
        case 'afa_cents':
            return t`Abschreibungen (AfA)`;
        case 'e1b_ergebnis':
        case 'e1b_ergebnis_cents':
            return t`Vermietungsergebnis (E1B)`;
        default:
            return key;
    }
}

function isMoneyEntry(entry: [string, unknown]): entry is [string, number] {
    return entry[0] !== 'pauschalen' && typeof entry[1] === 'number';
}

export default function EurIncomeTaxPanel({defaultProfitCents = 0, e1ByCode}: Props) {
    const help = useEurHelpTexts();
    const [ergonomicsEuro, setErgonomicsEuro] = useState('');
    const [workplaceEuro, setWorkplaceEuro] = useState('');
    const [profitEuro, setProfitEuro] = useState(defaultProfitCents === 0 ? '' : String(defaultProfitCents / 100));
    const [mode, setMode] = useState<EurIncomeTaxMode>('total');
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
            setResult(await calculateEurIncomeTax(profitCents, {mode}));
        } catch {
            setResult(null);
            setError(t`Die Einkommensteuer konnte nicht berechnet werden. Prüfen Sie den Gewinn und versuchen Sie es erneut.`);
        } finally {
            setIsLoading(false);
        }
    };

    const kzEntries = Object.entries(result?.kz ?? {});
    const steuerbilanz = result?.steuerbilanz;
    const steuerbilanzEntries = Object.entries(steuerbilanz ?? {}).filter(isMoneyEntry);
    const pauschalen = steuerbilanz?.pauschalen;
    const e40Code = pauschalen?.e40_code ?? '';
    const e40Label = pauschalen?.e40_label ?? '—';
    const e39Code = pauschalen?.e39_code ?? '';
    const e39Label = pauschalen?.e39_label ?? '—';
    const e1Rows = e1ByCodeRows(e1ByCode ?? result?.e1_by_code ?? result?.e1a_e1b);

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
                <label className="form-control">
                    <span className="label-text"><Trans>Rechenmodus</Trans></span>
                    <select
                        className="select select-bordered"
                        value={mode}
                        onChange={(event) => setMode(event.target.value as EurIncomeTaxMode)}
                        required
                    >
                        <option value="total"><Trans>Gesamt</Trans></option>
                        <option value="business"><Trans>Nur Betrieb</Trans></option>
                        <option value="rental"><Trans>Nur Vermietung</Trans></option>
                        <option value="forecast"><Trans>Prognose</Trans></option>
                    </select>
                </label>
                <div className="md:col-span-3">
                    <button type="button" className="btn btn-primary" onClick={submit} disabled={!isValid || isLoading}>
                        <Trans>Steuer berechnen</Trans>
                    </button>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <label className="form-control">
                    <span className="label-text flex items-center gap-2">
                        <Trans>Ergonomisches Mobiliar</Trans>
                        <span className="badge badge-outline">{KZ_ERGONOMICS}</span>
                        <EurHelpPopover help={help.data?.help.find((row) => row.kz === KZ_ERGONOMICS)}/>
                    </span>
                    <input
                        type="text"
                        inputMode="decimal"
                        className="input input-bordered"
                        value={ergonomicsEuro}
                        onChange={(event) => setErgonomicsEuro(event.target.value)}
                    />
                </label>
                <label className="form-control">
                    <span className="label-text flex items-center gap-2">
                        <Trans>Arbeitsplatzpauschale</Trans>
                        <span className="badge badge-outline">{KZ_WORKPLACE}</span>
                        <EurHelpPopover help={help.data?.help.find((row) => row.kz === '9215')}/>
                    </span>
                    <input
                        type="text"
                        inputMode="decimal"
                        className="input input-bordered"
                        value={workplaceEuro}
                        onChange={(event) => setWorkplaceEuro(event.target.value)}
                    />
                </label>
            </div>

            <details className="mt-4 rounded-box border border-base-300 p-3">
                <summary className="cursor-pointer font-medium"><Trans>Ausfüllhilfe je Kennzahl</Trans></summary>
                <ul className="mt-3 space-y-2">
                    {(help.data?.help ?? []).map((row) => (
                        <li key={`${row.kz}-${row.bezeichnung}`} className="flex items-center gap-2 text-sm">
                            <span className="badge badge-outline">{row.kz}</span>
                            <span>{row.bezeichnung}</span>
                            <EurHelpPopover help={row}/>
                        </li>
                    ))}
                </ul>
            </details>

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
                                        <td className="text-right">{stage.to_cents === null ? <Trans>offen</Trans> : cents(stage.to_cents)}</td>
                                        <td className="text-right">{formatPercent(stage.rate_per_10000 / 100)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {kzEntries.length > 0 && (
                        <div className="mt-6">
                            <h3 className="font-semibold"><Trans>Weitere Kennzahlen</Trans></h3>
                            <div className="overflow-x-auto mt-2">
                                <table className="table table-sm">
                                    <thead>
                                        <tr>
                                            <th><Trans>Kennzahl</Trans></th>
                                            <th className="text-right"><Trans>Betrag</Trans></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kzEntries.map(([code, value]) => (
                                            <tr key={code}>
                                                <td>{kzLabel(code)}</td>
                                                <td className="text-right">{cents(value)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {(steuerbilanzEntries.length > 0 || pauschalen) && (
                        <div className="mt-6">
                            <h3 className="font-semibold"><Trans>Steuerbilanz</Trans></h3>
                            {steuerbilanzEntries.length > 0 && (
                                <div className="overflow-x-auto mt-2">
                                    <table className="table table-sm">
                                        <thead>
                                            <tr>
                                                <th><Trans>Position</Trans></th>
                                                <th className="text-right"><Trans>Betrag</Trans></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {steuerbilanzEntries.map(([key, value]) => (
                                                <tr key={key}>
                                                    <td>{steuerbilanzLabel(key)}</td>
                                                    <td className="text-right">{cents(value)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {pauschalen && (pauschalen.e40_label || pauschalen.e39_label) && (
                                <div className="mt-3 rounded-box border border-base-300 p-3">
                                    <h4 className="text-sm font-medium"><Trans>Einordnung der Pauschalen</Trans></h4>
                                    <ul className="mt-1 text-sm space-y-1">
                                        {e40Code && (
                                            <li><Trans>E1A {e40Code}: {e40Label}</Trans></li>
                                        )}
                                        {e39Code && (
                                            <li><Trans>E1A {e39Code}: {e39Label}</Trans></li>
                                        )}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}

            {e1Rows.length > 0 && (
                <div className="mt-6">
                    <h3 className="font-semibold"><Trans>E1A/E1B je Kennzahl</Trans></h3>
                    <div className="overflow-x-auto mt-2">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Kennzahl</Trans></th>
                                    <th className="text-right"><Trans>Einnahmen</Trans></th>
                                    <th className="text-right"><Trans>Ausgaben</Trans></th>
                                    <th className="text-right"><Trans>Saldo</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {e1Rows.map((row) => (
                                    <tr key={row.code}>
                                        <td className="font-mono">{row.code}</td>
                                        <td className="text-right">{row.income_cents === null ? '—' : cents(row.income_cents)}</td>
                                        <td className="text-right">{row.expense_cents === null ? '—' : cents(row.expense_cents)}</td>
                                        <td className="text-right">
                                            {row.total_cents !== null
                                                ? cents(row.total_cents)
                                                : cents((row.income_cents ?? 0) - (row.expense_cents ?? 0))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
