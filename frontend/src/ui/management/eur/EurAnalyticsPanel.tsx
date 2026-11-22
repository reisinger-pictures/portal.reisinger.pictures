import type { ReactNode } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import { useEurAnalytics, type EurAnalyticsRow } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

const MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
];

interface Props {
    year: number;
}

interface TableProps {
    heading: ReactNode;
    rows: EurAnalyticsRow[];
}

function MarginTable({heading, rows}: TableProps) {
    if (rows.length === 0) {
        return (
            <div className="mt-4">
                <h3 className="font-semibold">{heading}</h3>
                <p className="text-sm opacity-70 mt-1">
                    <Trans>Für diese Auswertung liegen noch keine Daten vor. Erfassen Sie zuerst Buchungen im Journal.</Trans>
                </p>
            </div>
        );
    }

    return (
        <div className="mt-4">
            <h3 className="font-semibold">{heading}</h3>
            <div className="overflow-x-auto">
                <table className="table table-sm">
                    <thead>
                        <tr>
                            <th><Trans>Name</Trans></th>
                            <th className="text-right"><Trans>Umsatz</Trans></th>
                            <th className="text-right"><Trans>Kosten</Trans></th>
                            <th className="text-right"><Trans>Marge</Trans></th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.name}>
                                <td>{row.name}</td>
                                <td className="text-right">{cents(row.revenue_cents)}</td>
                                <td className="text-right">{cents(row.cost_cents)}</td>
                                <td className="text-right font-semibold">{cents(row.margin_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function EurAnalyticsPanel({year}: Props) {
    const analytics = useEurAnalytics(year);

    if (analytics.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Auswertungen werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (analytics.error || !analytics.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Auswertungen nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Die Auswertungen konnten nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie sie erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void analytics.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const data = analytics.data;
    const hasMonths = data.by_month.length > 0;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Auswertungen</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Umsatz und Marge je Kunde und Projekt sowie der Verlauf über das Jahr. Die Werte kommen aus dem Journal und werden nicht im Browser nachgerechnet.</Trans>
                </p>

                <MarginTable heading={<Trans>Kunden</Trans>} rows={data.by_customer}/>
                <MarginTable heading={<Trans>Projekte</Trans>} rows={data.by_project}/>

                <div className="mt-4">
                    <h3 className="font-semibold"><Trans>Monate</Trans></h3>
                    {!hasMonths ? (
                        <p className="text-sm opacity-70 mt-1">
                            <Trans>Noch keine Monatswerte vorhanden. Sobald Buchungen vorliegen, erscheint der Verlauf hier.</Trans>
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table table-sm">
                                <thead>
                                    <tr>
                                        <th><Trans>Monat</Trans></th>
                                        <th className="text-right"><Trans>Einnahmen</Trans></th>
                                        <th className="text-right"><Trans>Ausgaben</Trans></th>
                                        <th className="text-right"><Trans>Ergebnis</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.by_month.map((row) => (
                                        <tr key={row.month}>
                                            <td>{MONTHS[row.month - 1] ?? row.month}</td>
                                            <td className="text-right">{cents(row.income_cents)}</td>
                                            <td className="text-right">{cents(row.expense_cents)}</td>
                                            <td className="text-right font-semibold">{cents(row.profit_cents)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
