import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useMyPayouts } from '../../logic/usePayouts';
import { formatMoney } from '../../logic/utils';
import ErrorMessage from '../components/ErrorMessage';

export default function PhotographerPayoutsView() {
    const { statements, isLoading } = useMyPayouts();

    if (isLoading) return <div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (!statements) return <div className="p-8"><ErrorMessage message={t`Fehler beim Laden der Abrechnungen.`} /></div>;

    const statusBadge = (status: string) => {
        switch(status) {
            case 'pending': return <span className="badge badge-warning badge-sm"><Trans>In Prüfung</Trans></span>;
            case 'rollover': return <span className="badge badge-ghost badge-sm"><Trans>&lt; 50€ (Übertrag ins nächste Monat)</Trans></span>;
            case 'approved': return <span className="badge badge-info badge-sm"><Trans>Gutschrift erstellt (Bitte Rechnung legen)</Trans></span>;
            case 'paid': return <span className="badge badge-success badge-sm text-white"><Trans>Ausbezahlt</Trans></span>;
            default: return <span className="badge badge-ghost badge-sm">{status}</span>;
        }
    };

    return (
        <div className="container mx-auto p-4 md:p-8 max-w-5xl">
            <h1 className="text-3xl font-bold mb-2 flex items-center gap-2">
                <span className="iconify mdi--cash-multiple text-primary"></span> <Trans>Meine Abrechnungen</Trans>
            </h1>
            <p className="opacity-70 mb-8"><Trans>Übersicht deiner monatlichen Einnahmen durch Flatrate-Anteile und Aufpreise.</Trans></p>

            <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th><Trans>Zeitraum</Trans></th>
                            <th><Trans>Belegnummer</Trans></th>
                            <th className="text-right"><Trans>Zusammensetzung</Trans></th>
                            <th className="text-right"><Trans>Gesamt (Auszahlung)</Trans></th>
                            <th><Trans>Status</Trans></th>
                        </tr>
                    </thead>
                    <tbody>
                        {statements.map(stmt => (
                            <tr key={stmt.id}>
                                <td className="whitespace-nowrap font-bold">{stmt.month} / {stmt.year}</td>
                                <td className="font-mono text-sm opacity-80">{stmt.sequence_number}</td>
                                <td className="text-right text-sm font-mono opacity-80">
                                    <Trans>Pool:</Trans> {formatMoney(stmt.pool_earnings_cents)}<br/>
                                    <Trans>Delta (Aufpreis):</Trans> {formatMoney(stmt.delta_surcharge_earnings_cents)}<br/>
                                    <span className="opacity-50"><Trans>Rollover:</Trans> {formatMoney(stmt.rolled_over_amount_cents)}</span>
                                </td>
                                <td className="text-right font-mono font-bold text-lg text-primary">
                                    {formatMoney(stmt.total_payable_cents)}
                                </td>
                                <td>{statusBadge(stmt.status)}</td>
                            </tr>
                        ))}
                        {statements.length === 0 && <tr><td colSpan={5} className="text-center py-10 opacity-50"><Trans>Du hast bisher noch keine Abrechnungen erhalten.</Trans></td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
