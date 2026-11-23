import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import { EUR_MONTHS as MONTHS, type EurEntriesResponse } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    data?: EurEntriesResponse;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
    onCreate: () => void;
}

export default function EurEntriesPanel({data, isLoading, error, onRetry, onCreate}: Props) {
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
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="card-title text-primary"><Trans>Buchungen</Trans></h2>
                        <p className="text-sm opacity-70"><Trans>Alle erfassten Einnahmen und Ausgaben des Jahres.</Trans></p>
                    </div>
                    <button type="button" className="btn btn-primary btn-sm" onClick={onCreate}>
                        <Trans>+ Buchung</Trans>
                    </button>
                </div>

                {data.entries.length === 0 ? (
                    <p className="opacity-70 mt-4"><Trans>Noch keine Buchungen erfasst. Legen Sie die erste Buchung an.</Trans></p>
                ) : (
                    <>
                    <div className="md:hidden mt-4 space-y-3">
                        {data.entries.map((entry) => (
                            <article key={entry.id} className="card card-compact bg-base-200 border border-base-300">
                                <div className="card-body gap-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="font-semibold">{MONTHS[entry.month - 1]}</h3>
                                        <span className="font-semibold">{cents(entry.amount_cents)}</span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`badge ${entry.direction === 'income' ? 'badge-success' : 'badge-error'} badge-outline`}>
                                            {entry.direction === 'income' ? <Trans>Einnahme</Trans> : <Trans>Ausgabe</Trans>}
                                        </span>
                                        <span>{entry.kind_type}</span>
                                    </div>
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                        <dt className="opacity-60"><Trans>Anteil</Trans></dt>
                                        <dd className="text-right">{entry.business_share} %</dd>
                                        <dt className="opacity-60"><Trans>Betrieblich</Trans></dt>
                                        <dd className="text-right">{cents(entry.business_amount_cents)}</dd>
                                    </dl>
                                    {(entry.checks ?? []).filter((check) => !check.ok).length > 0 && (
                                        <div className="flex flex-wrap gap-1">
                                            {(entry.checks ?? []).filter((check) => !check.ok).map((check) => (
                                                <span key={check.code} className="badge badge-warning badge-outline"
                                                    title={check.hint}>
                                                    {check.message}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                    <div className="hidden md:block overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Monat</Trans></th>
                                    <th><Trans>Art</Trans></th>
                                    <th className="text-right"><Trans>Betrag</Trans></th>
                                    <th className="text-right"><Trans>Anteil</Trans></th>
                                    <th className="text-right"><Trans>Betrieblich</Trans></th>
                                    <th><Trans>Prüfung</Trans></th>
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
                                        <td>
                                            {(entry.checks ?? []).filter((check) => !check.ok).map((check) => (
                                                <span key={check.code} className="badge badge-warning badge-outline mr-1"
                                                    title={check.hint}>
                                                    {check.message}
                                                </span>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    </>
                )}
            </div>
        </div>
    );
}
