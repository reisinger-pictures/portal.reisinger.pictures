import {t} from '@lingui/core/macro';
import {Trans} from '@lingui/react/macro';
import {formatEuro} from '../../../logic/formatCurrency';
import {
    EUR_MONTHS as MONTHS,
    useEurExpenseTypeAnalytics,
    useEurIncomeTypeAnalytics,
    useEurPaymentReconciliation,
    type EurTypeMatrix,
    type EurTypeMatrixRow,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

// Die ODS-Matrix rundet den Prozentanteil auf eine Stelle. Dieser Helfer ist
// bewusst lokal: er ist ein Anteil in 0–100 % (kein Basis-Punkte-Wert aus der
// Preisdomäne, für die `formatPercent` zuständig ist).
const SHARE_FORMATTER = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});
const share = (part: number, total: number): string => total === 0
    ? '0,0 %'
    : `${SHARE_FORMATTER.format((part / total) * 100)} %`;

interface TypeMatrixTableProps {
    heading: React.ReactNode;
    matrix?: EurTypeMatrix;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
}

function TypeMatrixTable({heading, matrix, isLoading, error, onRetry}: TypeMatrixTableProps) {
    if (isLoading) {
        return (
            <div className="mt-6" role="status">
                <h3 className="font-semibold">{heading}</h3>
                <span className="loading loading-spinner loading-sm mt-2" aria-hidden="true"/>
            </div>
        );
    }

    if (error || !matrix) {
        return (
            <div className="mt-6" role="alert">
                <h3 className="font-semibold">{heading}</h3>
                <p className="text-sm opacity-70 mt-1">
                    <Trans>Diese Auswertung konnte nicht geladen werden.</Trans>
                </p>
                <button type="button" className="btn btn-outline btn-sm mt-2" onClick={onRetry}>
                    <Trans>Erneut laden</Trans>
                </button>
            </div>
        );
    }

    const withSources = matrix.kind === 'expense';
    const rows: EurTypeMatrixRow[] = matrix.types;
    const deltaText = cents(matrix.delta_cents);

    return (
        <div className="mt-6">
            <h3 className="font-semibold">{heading}</h3>
            {rows.length === 0 ? (
                <p className="text-sm opacity-70 mt-1">
                    <Trans>Noch keine Buchungen für diese Auswertung vorhanden.</Trans>
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="table table-sm">
                        <thead>
                            <tr>
                                <th><Trans>Art</Trans></th>
                                {MONTHS.map((month) => <th key={month} className="text-right">{month.slice(0, 3)}</th>)}
                                <th className="text-right"><Trans>Summe</Trans></th>
                                <th className="text-right"><Trans>Anteil</Trans></th>
                                {withSources && <th className="text-right"><Trans>Herkunft A/AV/WEB/FR</Trans></th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.type}>
                                    <td>{row.type}</td>
                                    {MONTHS.map((_, index) => (
                                        <td key={index} className="text-right">{cents(row.months[String(index + 1)] ?? 0)}</td>
                                    ))}
                                    <td className="text-right font-semibold">{cents(row.total_cents)}</td>
                                    <td className="text-right">{share(row.total_cents, matrix.total_cents)}</td>
                                    {withSources && (
                                        <td className="text-right">
                                            {row.sources
                                                ? `A ${cents(row.sources.A)} · AV ${cents(row.sources.AV)} · WEB ${cents(row.sources.WEB)} · FR ${cents(row.sources.FR)}`
                                                : '—'}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="font-semibold">
                                <td><Trans>Summe</Trans></td>
                                {MONTHS.map((_, index) => (
                                    <td key={index} className="text-right">{cents(matrix.month_totals[String(index + 1)] ?? 0)}</td>
                                ))}
                                <td className="text-right">{cents(matrix.total_cents)}</td>
                                <td className="text-right">100,0 %</td>
                                {withSources && <td/>}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
            <p className={`text-sm mt-2 ${matrix.match ? 'text-success' : 'text-error'}`} role="status">
                {matrix.match
                    ? t`✓✓ Abgleich mit dem Journal: kein Unterschied.`
                    : t`Δ Abweichung zum Journal: ${deltaText}.`}
            </p>
        </div>
    );
}

interface Props {
    year: number;
}

export default function EurAnalyticsMatricesPanel({year}: Props) {
    const income = useEurIncomeTypeAnalytics(year);
    const expense = useEurExpenseTypeAnalytics(year);
    const payment = useEurPaymentReconciliation(year);

    const methods = payment.data?.methods ?? [];
    const hasMethods = methods.some((method) => method.months.some((month) => month.in_cents !== 0 || month.out_cents !== 0));

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Auswertungsmatrizen (BE-ART / BA-ART / ZA)</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Einnahmen und Ausgaben je Art und Monat sowie der Saldoabgleich je Zahlungsart. Die Beträge stammen aus dem Journal und werden nicht im Browser nachgerechnet.</Trans>
                </p>

                <TypeMatrixTable
                    heading={<Trans>Einnahmen nach Art (BE-ART)</Trans>}
                    matrix={income.data}
                    isLoading={income.isLoading}
                    error={income.error}
                    onRetry={() => void income.mutate()}
                />
                <TypeMatrixTable
                    heading={<Trans>Ausgaben nach Art (BA-ART)</Trans>}
                    matrix={expense.data}
                    isLoading={expense.isLoading}
                    error={expense.error}
                    onRetry={() => void expense.mutate()}
                />

                <div className="mt-6">
                    <h3 className="font-semibold"><Trans>Zahlungsart (ZA)</Trans></h3>
                    {payment.isLoading ? (
                        <span className="loading loading-spinner loading-sm mt-2" aria-hidden="true"/>
                    ) : payment.error || !payment.data ? (
                        <div role="alert">
                            <p className="text-sm opacity-70 mt-1">
                                <Trans>Diese Auswertung konnte nicht geladen werden.</Trans>
                            </p>
                            <button type="button" className="btn btn-outline btn-sm mt-2" onClick={() => void payment.mutate()}>
                                <Trans>Erneut laden</Trans>
                            </button>
                        </div>
                    ) : !hasMethods ? (
                        <p className="text-sm opacity-70 mt-1">
                            <Trans>Noch keine Zahlungsflüsse vorhanden.</Trans>
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table table-sm">
                                <thead>
                                    <tr>
                                        <th><Trans>Zahlungsart</Trans></th>
                                        <th className="text-right"><Trans>Startsaldo</Trans></th>
                                        <th className="text-right"><Trans>Zuflüsse</Trans></th>
                                        <th className="text-right"><Trans>Abflüsse</Trans></th>
                                        <th className="text-right"><Trans>Endsaldo</Trans></th>
                                        <th className="text-right"><Trans>Abgleich</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {methods.map((method) => {
                                        const inflow = method.months.reduce((sum, month) => sum + month.in_cents, 0);
                                        const outflow = method.months.reduce((sum, month) => sum + month.out_cents, 0);
                                        return (
                                            <tr key={method.method}>
                                                <td>{method.method}</td>
                                                <td className="text-right">
                                                    {method.opening_balance_cents === null
                                                        ? '—'
                                                        : cents(method.opening_balance_cents)}
                                                </td>
                                                <td className="text-right">{cents(inflow)}</td>
                                                <td className="text-right">{cents(outflow)}</td>
                                                <td className="text-right font-semibold">{cents(method.closing_balance_cents)}</td>
                                                <td className="text-right">{method.status || '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
