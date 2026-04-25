import { useMyPayouts } from '../../logic/usePayouts';
import { formatMoney } from '../../logic/utils';
import ErrorMessage from '../components/ErrorMessage';
import PageLayout from '../components/PageLayout';

export default function PhotographerPayoutsView() {
    const { statements, isLoading } = useMyPayouts();

    if (isLoading) return <PageLayout currentView="payouts"><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (!statements) return <PageLayout currentView="payouts"><div className="p-8"><ErrorMessage message="Fehler beim Laden der Abrechnungen." /></div></PageLayout>;

    const statusBadge = (status: string) => {
        switch(status) {
            case 'pending': return <span className="badge badge-warning badge-sm">In Prüfung</span>;
            case 'rollover': return <span className="badge badge-ghost badge-sm">&lt; 50€ (Übertrag ins nächste Monat)</span>;
            case 'approved': return <span className="badge badge-info badge-sm">Gutschrift erstellt (Bitte Rechnung legen)</span>;
            case 'paid': return <span className="badge badge-success badge-sm text-white">Ausbezahlt</span>;
            default: return <span className="badge badge-ghost badge-sm">{status}</span>;
        }
    };

    return (
        <PageLayout currentView="payouts">
            <div className="container mx-auto p-4 md:p-8 max-w-5xl">
                <h1 className="text-3xl font-bold mb-2 flex items-center gap-2">
                    <span className="iconify mdi--cash-multiple text-primary"></span> Meine Abrechnungen
                </h1>
                <p className="opacity-70 mb-8">Übersicht deiner monatlichen Einnahmen durch Flatrate-Anteile und Aufpreise.</p>

                <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                    <table className="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Zeitraum</th>
                                <th>Belegnummer</th>
                                <th className="text-right">Zusammensetzung</th>
                                <th className="text-right">Gesamt (Auszahlung)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {statements.map(stmt => (
                                <tr key={stmt.id}>
                                    <td className="whitespace-nowrap font-bold">{stmt.month} / {stmt.year}</td>
                                    <td className="font-mono text-sm opacity-80">{stmt.sequence_number}</td>
                                    <td className="text-right text-sm font-mono opacity-80">
                                        Pool: {formatMoney(stmt.pool_earnings_cents)}<br/>
                                        Delta (Aufpreis): {formatMoney(stmt.delta_surcharge_earnings_cents)}<br/>
                                        <span className="opacity-50">Rollover: {formatMoney(stmt.rolled_over_amount_cents)}</span>
                                    </td>
                                    <td className="text-right font-mono font-bold text-lg text-primary">
                                        {formatMoney(stmt.total_payable_cents)}
                                    </td>
                                    <td>{statusBadge(stmt.status)}</td>
                                </tr>
                            ))}
                            {statements.length === 0 && <tr><td colSpan={5} className="text-center py-10 opacity-50">Du hast bisher noch keine Abrechnungen erhalten.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </PageLayout>
    );
}
