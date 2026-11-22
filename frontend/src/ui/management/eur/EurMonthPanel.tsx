import { useState, type ReactNode } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import {
    closeEurMonth,
    e1ByCodeRows,
    EUR_MONTHS as MONTHS,
    useEurMonth,
    type EurMonthLine,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    year: number;
    month: number;
    onMonthChange: (month: number) => void;
}

function lineTypeLabel(type: EurMonthLine['type']): ReactNode {
    switch (type) {
        case 'E': return <Trans>Einnahme</Trans>;
        case 'A': return <Trans>Ausgabe</Trans>;
        case 'WEB': return <Trans>Wareneingang</Trans>;
        case 'FR': return <Trans>Reisekosten</Trans>;
        default: return type;
    }
}

/** Shared, catalogued labels for every social-insurance key the month endpoint returns. */
function svLabel(key: string): ReactNode {
    switch (key) {
        case 'max_bgl': return <Trans>Maximale Beitragsgrundlage</Trans>;
        case 'kv_min': return <Trans>Krankenversicherung Mindestbeitrag</Trans>;
        case 'gsvg_pv_min': return <Trans>Mindestbeitrag Pensionsversicherung GSVG</Trans>;
        case 'fsvg_pv_min': return <Trans>Mindestbeitrag Pensionsversicherung FSVG</Trans>;
        case 'gsvg_ns_pv_min': return <Trans>Mindestbeitrag Pensionsversicherung GSVG (Neue Selbständige)</Trans>;
        case 'uv_pauschale': return <Trans>Unfallversicherung Pauschale</Trans>;
        case 'kv_satz': return <Trans>Krankenversicherungssatz</Trans>;
        case 'gsvg_pv_satz': return <Trans>Pensionsversicherungssatz GSVG</Trans>;
        case 'fsvg_pv_satz': return <Trans>Pensionsversicherungssatz FSVG</Trans>;
        case 'svs_satz': return <Trans>Satz der Selbständigenvorsorge</Trans>;
        default: return <Trans>Sonstiger Wert</Trans>;
    }
}

/** `report.status` is `open` or `closed` (DB default `open`). */
function reportStatusLabel(status: string): string {
    switch (status) {
        case 'open': return t`offen`;
        case 'closed': return t`abgeschlossen`;
        default: return t`unbekannt`;
    }
}

/** `signature.status` is `none`, `pending`, `signed` or `failed`. */
function signatureStatusLabel(status: string): string {
    switch (status) {
        case 'none': return t`keine`;
        case 'pending': return t`ausstehend`;
        case 'signed': return t`vorhanden`;
        case 'failed': return t`fehlgeschlagen`;
        default: return t`unbekannt`;
    }
}

const withLamp = (text: string): string => (text.startsWith('💡') ? text : `💡 ${text}`);
const withWarning = (text: string): string => (text.startsWith('⚠') ? text : `⚠ ${text}`);

export default function EurMonthPanel({year, month, onMonthChange}: Props) {
    const monthData = useEurMonth(year, month);
    const [isClosing, setIsClosing] = useState(false);
    const [closeMessage, setCloseMessage] = useState<string | null>(null);
    const [closeError, setCloseError] = useState<string | null>(null);

    const close = async () => {
        setIsClosing(true);
        setCloseMessage(null);
        setCloseError(null);

        try {
            await closeEurMonth({year, month});
            setCloseMessage(t`Der Monat ist abgeschlossen. Der Vorgang ist im Prüfprotokoll dokumentiert.`);
            void monthData.mutate();
        } catch {
            setCloseError(t`Der Monat konnte nicht abgeschlossen werden. Prüfen Sie die Hinweise unten und versuchen Sie es erneut.`);
        } finally {
            setIsClosing(false);
        }
    };

    if (monthData.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Monatsdaten werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (monthData.error || !monthData.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Monatsdaten nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Die Monatsdaten konnten nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie den Monat erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void monthData.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const data = monthData.data;
    const totals = data.totals;
    const amounts = Object.entries(data.social_insurance.amounts_cents);
    const rates = Object.entries(data.social_insurance.rates_per_10000);
    const reportStatus = reportStatusLabel(data.report.status);
    const signatureStatus = signatureStatusLabel(data.signature.status);

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="card-title text-primary"><Trans>Monatsabschluss</Trans></h2>
                        <p className="text-sm opacity-70">
                            <Trans>Alle Zeilen, Summen und Prüfhinweise des Monats auf einen Blick.</Trans>
                        </p>
                    </div>
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat</Trans></span>
                        <select className="select select-bordered select-sm" value={month}
                            onChange={(event) => onMonthChange(Number(event.target.value))} required>
                            {MONTHS.map((name, index) => (
                                <option key={name} value={index + 1}>{name}</option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mt-5">
                    <div className="rounded-box border border-base-300 p-3">
                        <div className="text-xs opacity-60"><Trans>Einnahmen</Trans></div>
                        <div className="font-semibold">{cents(totals.income_cents)}</div>
                    </div>
                    <div className="rounded-box border border-base-300 p-3">
                        <div className="text-xs opacity-60"><Trans>Ausgaben</Trans></div>
                        <div className="font-semibold">{cents(totals.expense_cents)}</div>
                    </div>
                    <div className="rounded-box border border-base-300 p-3">
                        <div className="text-xs opacity-60"><Trans>Wareneingang</Trans></div>
                        <div className="font-semibold">{cents(totals.warehouse_cents)}</div>
                    </div>
                    <div className="rounded-box border border-base-300 p-3">
                        <div className="text-xs opacity-60"><Trans>Umsatzsteuer</Trans></div>
                        <div className="font-semibold">{cents(totals.vat_cents)}</div>
                    </div>
                    <div className="rounded-box border border-base-300 p-3">
                        <div className="text-xs opacity-60"><Trans>Stunden</Trans></div>
                        <div className="font-semibold">{Math.floor(totals.hours_minutes / 60)} h {totals.hours_minutes % 60} min</div>
                    </div>
                </div>

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Zeilen des Monats</Trans></h3>
                {data.lines.length === 0 ? (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Für diesen Monat sind noch keine Zeilen vorhanden. Erfassen Sie Buchungen oder Wareneingänge, damit der Monat geprüft werden kann.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-2">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Art</Trans></th>
                                    <th><Trans>Tag</Trans></th>
                                    <th><Trans>Beschreibung</Trans></th>
                                    <th><Trans>Beleg</Trans></th>
                                    <th className="text-right"><Trans>Betrag</Trans></th>
                                    <th className="text-right"><Trans>Betrieblich</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => (
                                    <tr key={`${line.type}-${line.day ?? 0}-${index}`}>
                                        <td>{lineTypeLabel(line.type)}</td>
                                        <td>{line.day ?? '—'}</td>
                                        <td>
                                            {line.description}
                                            {line.warnings.map((warning) => (
                                                <div key={warning} className="text-xs text-warning">{withWarning(warning)}</div>
                                            ))}
                                            {line.hints.map((hint) => (
                                                <div key={hint} className="text-xs opacity-70">{withLamp(hint)}</div>
                                            ))}
                                        </td>
                                        <td>{line.receipt_no ?? '—'}</td>
                                        <td className="text-right">{cents(line.amount_cents)}</td>
                                        <td className="text-right">{cents(line.business_amount_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Prüfungen</Trans></h3>
                {data.checks.length === 0 ? (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Für diesen Monat sind keine Prüfhinweise hinterlegt.</Trans>
                    </p>
                ) : (
                    <ul className="mt-2 space-y-3">
                        {data.checks.map((check) => (
                            <li key={check.code} className="rounded-box border border-base-300 p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`badge ${check.ok ? 'badge-success' : 'badge-warning'} badge-outline`}>
                                        {check.ok ? <Trans>OK</Trans> : <Trans>Prüfen</Trans>}
                                    </span>
                                    <span className="font-medium">{check.message}</span>
                                </div>
                                {check.hint !== '' && (
                                    <p className="text-sm opacity-80 mt-1">{withLamp(check.hint)}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Sozialversicherung und Einkommensteuer</Trans></h3>
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                    {amounts.map(([key, value]) => (
                        <div key={key} className="rounded-box border border-base-300 p-3">
                            <dt className="text-xs opacity-60">{svLabel(key)}</dt>
                            <dd className="font-semibold">{cents(value)}</dd>
                        </div>
                    ))}
                    {rates.map(([key, value]) => (
                        <div key={key} className="rounded-box border border-base-300 p-3">
                            <dt className="text-xs opacity-60">{svLabel(key)}</dt>
                            <dd className="font-semibold">{formatPercent(value)}</dd>
                        </div>
                    ))}
                    <div className="rounded-box border border-base-300 p-3">
                        <dt className="text-xs opacity-60"><Trans>Steuerlicher Gewinn</Trans></dt>
                        <dd className="font-semibold">{cents(data.income_tax.profit_cents)}</dd>
                    </div>
                    <div className="rounded-box border border-base-300 p-3">
                        <dt className="text-xs opacity-60"><Trans>Geschätzte Einkommensteuer</Trans></dt>
                        <dd className="font-semibold text-primary">{cents(data.income_tax.tax_cents)}</dd>
                    </div>
                </dl>

                {e1ByCodeRows(data.e1_by_code).length > 0 && (
                    <>
                        <div className="divider my-4"/>

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
                                    {e1ByCodeRows(data.e1_by_code).map((row) => (
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
                    </>
                )}

                <div className="divider my-4"/>

                <div className="flex flex-wrap items-center gap-3">
                    <button type="button" className="btn btn-primary btn-sm" onClick={close} disabled={isClosing}>
                        <Trans>Monat abschließen</Trans>
                    </button>
                    <span className="text-sm opacity-70">
                        <Trans>Status: {reportStatus} · Signatur: {signatureStatus}</Trans>
                    </span>
                </div>
                {closeMessage && <p className="alert alert-success mt-3" role="status">{closeMessage}</p>}
                {closeError && <p className="alert alert-error mt-3" role="alert">{closeError}</p>}
            </div>
        </div>
    );
}
