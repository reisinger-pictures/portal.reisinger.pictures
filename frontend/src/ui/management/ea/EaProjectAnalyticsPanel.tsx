import {t} from '@lingui/core/macro';
import {Trans} from '@lingui/react/macro';
import {formatEuro} from '../../../logic/formatCurrency';
import {
    EUR_MONTHS as MONTHS,
    useEaProjectAnalytics,
    type EaProjectAnalyticsMonth,
} from '../../../logic/useEa';

const cents = (value: number): string => formatEuro(value / 100);
const minutes = (value: number): string => `${value} Min.`;

// Wie in BE-ART/BA-ART wird der Anteil auf eine Dezimalstelle gerundet. Das ist
// ein Anteil in 0–100 %, kein Basispunkt-Wert aus der Preisdomäne.
const SHARE_FORMATTER = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});
const share = (part: number, total: number): string => total === 0
    ? '0,0 %'
    : `${SHARE_FORMATTER.format((part / total) * 100)} %`;

interface CellValues {
    income_cents: number;
    expense_cents: number;
    hours_minutes: number;
    result_cents: number;
}

/** Vier Werte je Monatszelle, bewusst kompakt: E/A oben, Stunden/Ergebnis unten. */
function CellNumbers({cell}: {cell: CellValues}) {
    return (
        <>
            <span className="text-success" title={t`Einnahmen`}>{cents(cell.income_cents)}</span>
            {' / '}
            <span className="text-error" title={t`Ausgaben`}>{cents(cell.expense_cents)}</span>
            <br/>
            <span title={t`Arbeitsstunden in Minuten`}>{minutes(cell.hours_minutes)}</span>
            {' / '}
            <span className="font-semibold" title={t`Ergebnis`}>{cents(cell.result_cents)}</span>
        </>
    );
}

interface Props {
    year: number;
}

export default function EaProjectAnalyticsPanel({year}: Props) {
    const analytics = useEaProjectAnalytics(year);
    const data = analytics.data;
    const deviation = data?.checks.filter((check) => !check.ok).map((check) => check.message).join(' ') ?? '';

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="ea-project-analytics-panel">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Projekte-Auswertung (EUE-121)</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>
                        Einnahmen, Ausgaben und Arbeitsstunden je Projekt und Monat. Je Zelle oben Einnahmen / Ausgaben,
                        unten Stunden / Ergebnis. Alle Beträge sind ganze Cent, alle Zeiten ganze Minuten.
                    </Trans>
                </p>

                {analytics.isLoading ? (
                    <div className="mt-4" role="status">
                        <span className="loading loading-spinner loading-sm" aria-hidden="true"/>
                    </div>
                ) : analytics.error || !data ? (
                    <div className="mt-4" role="alert">
                        <p className="text-sm opacity-70">
                            <Trans>Diese Auswertung konnte nicht geladen werden.</Trans>
                        </p>
                        <button type="button" className="btn btn-outline btn-sm mt-2" onClick={() => void analytics.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                ) : (
                    <>
                        {data.projects.length === 0 ? (
                            <p className="text-sm opacity-70 mt-4" role="status">
                                <Trans>Noch keine Buchungen oder Stunden für die Projekte-Auswertung vorhanden.</Trans>
                            </p>
                        ) : (
                            <div className="overflow-x-auto mt-4">
                                <table className="table table-sm">
                                    <thead>
                                        <tr>
                                            <th><Trans>Projekt</Trans></th>
                                            {MONTHS.map((month) => <th key={month} className="text-right">{month.slice(0, 3)}</th>)}
                                            <th className="text-right"><Trans>Summe</Trans></th>
                                            <th className="text-right"><Trans>Anteil</Trans></th>
                                            <th className="text-right"><Trans>Cent pro Minute</Trans></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.projects.map((row) => (
                                            <tr key={row.project} data-testid={`ea-project-row-${row.project}`}>
                                                <th scope="row" className="text-left font-normal">{row.project}</th>
                                                {row.months.map((cell: EaProjectAnalyticsMonth) => (
                                                    <td
                                                        key={cell.month}
                                                        className="text-right text-xs whitespace-nowrap"
                                                        data-testid={`ea-project-cell-${row.project}-${cell.month}`}
                                                    >
                                                        <CellNumbers cell={cell}/>
                                                    </td>
                                                ))}
                                                <td className="text-right text-xs whitespace-nowrap">
                                                    <CellNumbers cell={row.totals}/>
                                                </td>
                                                <td className="text-right">
                                                    {share(row.totals.income_cents, data.totals.income_cents)}
                                                </td>
                                                <td className="text-right">
                                                    {row.totals.result_per_hour_cents === null
                                                        ? '—'
                                                        : row.totals.result_per_hour_cents}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="font-semibold">
                                            <th scope="row" className="text-left"><Trans>Summe</Trans></th>
                                            {data.month_totals.map((cell) => (
                                                <td key={cell.month} className="text-right text-xs whitespace-nowrap">
                                                    <CellNumbers cell={cell}/>
                                                </td>
                                            ))}
                                            <td className="text-right text-xs whitespace-nowrap">
                                                <CellNumbers cell={data.totals}/>
                                            </td>
                                            <td className="text-right">100,0 %</td>
                                            <td className="text-right">
                                                {data.totals.result_per_hour_cents === null
                                                    ? '—'
                                                    : data.totals.result_per_hour_cents}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}

                        <p className={`text-sm mt-3 ${data.match ? 'text-success' : 'text-error'}`} role="status">
                            {data.match
                                ? t`✓✓ Abgleich Projekte: alle Buchungen und Stunden sind erfasst.`
                                : t`Δ Abweichung Projekte: ${deviation}.`}
                        </p>

                        <dl className="mt-3 text-sm space-y-1">
                            <div>
                                <dt className="font-semibold inline"><Trans>Vorjahres-AfA</Trans>: </dt>
                                <dd className="inline">{cents(data.prior_year_afa_cents)}</dd>
                            </div>
                            <div>
                                <dt className="font-semibold inline"><Trans>SV-Bereinigung (KZ 9225)</Trans>: </dt>
                                <dd className="inline">{cents(data.sv_excluded_cents)}</dd>
                            </div>
                            <div>
                                <dt className="font-semibold inline"><Trans>Stunden ohne Projekt</Trans>: </dt>
                                <dd className="inline">{minutes(data.undistributed_hours_minutes)}</dd>
                            </div>
                        </dl>
                        <p className="text-xs opacity-70 mt-2">
                            <Trans>
                                Die Vorjahres-AfA wird als Gesamtbetrag ausgewiesen: Im Portal lassen sich Anlagen
                                keinem Projekt zuordnen (D-85), daher erfolgt keine Verteilung auf Projekte.
                                Gebuchte 9225-Beiträge werden außer im UV-Modus aus den Ausgaben herausgerechnet.
                                „Cent pro Minute" ist das Ergebnis geteilt durch die erfassten Minuten.
                            </Trans>
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
