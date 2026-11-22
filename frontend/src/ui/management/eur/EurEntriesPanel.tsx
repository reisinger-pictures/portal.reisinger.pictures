import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import type { EurEntriesResponse } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

const MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
];

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
                    <div className="overflow-x-auto mt-4">
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
            </div>
        </div>
    );
}
