import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useState} from 'react';
import {useAdminPayouts} from '../../logic/usePayouts';
import {formatMoney} from '../../logic/utils';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';

export default function ManagementPayoutsView() {
    const {data, isLoading, calculateMonth, updateStatus} = useAdminPayouts();
    const {showToast, confirm} = useUI();
    const [month, setMonth] = useState(new Date().getMonth() + 1);
    const [year, setYear] = useState(new Date().getFullYear());
    const [netPool, setNetPool] = useState('');
    const [isCalculating, setIsCalculating] = useState(false);

    if (isLoading) return <div className="p-10 flex justify-center"><span
        className="loading loading-spinner loading-lg"></span></div>;
    if (!data) return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden der Payouts.`}/></div>;

    const handleCalculate = async (e: React.FormEvent) => {
        e.preventDefault();
        const cents = Math.round(parseFloat(netPool.replace(',', '.')) * 100);
        if (isNaN(cents) || cents < 0) {
            showToast('error', t`Bitte einen gültigen Betrag eingeben.`);
            return;
        }
        const formattedPayout = formatMoney(cents);
        if (!(await confirm({
            title: t`Abrechnungslauf starten?`,
            message: t`Möchtest du den Pool für ${month}/${year} wirklich mit ${formattedPayout} berechnen? Bestehende (nicht ausbezahlte) Statements für dieses Monat werden überschrieben.`,
            confirmText: t`Berechnen`
        }))) return;

        setIsCalculating(true);
        try {
            await calculateMonth(month, year, cents);
            showToast('success', t`Abrechnung erfolgreich durchgeführt!`);
        } catch (error: unknown) {
            showToast('error', error instanceof Error ? error.message : t`Fehler bei der Berechnung`);
        }
        setIsCalculating(false);
    };

    const statusBadge = (status: string) => {
        switch (status) {
            case 'pending':
                return <span className="badge badge-warning badge-sm"><Trans>Wartet auf Freigabe</Trans></span>;
            case 'rollover':
                return <span className="badge badge-ghost badge-sm"><Trans>&lt; 50€ (Rollover)</Trans></span>;
            case 'approved':
                return <span className="badge badge-info badge-sm"><Trans>Freigegeben</Trans></span>;
            case 'paid':
                return <span className="badge badge-success badge-sm text-white"><Trans>Ausbezahlt</Trans></span>;
            default:
                return <span className="badge badge-ghost badge-sm">{status}</span>;
        }
    };

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <h1 className="text-4xl font-bold mb-2"><Trans>Abrechnungen (Payouts)</Trans></h1>
            <p className="opacity-70 mb-8"><Trans>Verwalte Flatrate-Pools und Fotografen-Auszahlungen.</Trans></p>

            <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm mb-8">
                <h2 className="font-bold text-xl mb-4"><Trans>Neuen Abrechnungslauf starten</Trans></h2>
                <form onSubmit={handleCalculate} className="flex flex-col md:flex-row items-end gap-4">
                    <div className="form-control w-full md:w-32">
                        <label className="label"><span className="label-text font-bold"><Trans>Monat</Trans></span></label>
                        <input type="number" min="1" max="12" value={month}
                               onChange={e => setMonth(parseInt(e.target.value))}
                               className="input input-bordered" required/>
                    </div>
                    <div className="form-control w-full md:w-32">
                        <label className="label"><span className="label-text font-bold"><Trans>Jahr</Trans></span></label>
                        <input type="number" min="2024" value={year} onChange={e => setYear(parseInt(e.target.value))}
                               className="input input-bordered" required/>
                    </div>
                    <div className="form-control w-full md:w-48">
                        <label className="label">
                            <span className="label-text font-bold"><Trans>Flatrate-Netto (€)</Trans></span>
                        </label>
                        <input type="number" step="0.01" value={netPool} onChange={e => setNetPool(e.target.value)}
                                placeholder={t`z.B. 2500.00`} className="input input-bordered font-mono"
                               required/>
                    </div>
                    <button type="submit" disabled={isCalculating}
                            className="btn btn-primary h-10 px-8 w-full md:w-auto">
                        {isCalculating ? <span className="loading loading-spinner"></span> : <Trans>Berechnen</Trans>}
                    </button>
                </form>
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
                <div className="xl:col-span-1">
                    <h2 className="font-bold text-xl mb-4"><Trans>Flatrate-Pools</Trans></h2>
                    <div className="flex flex-col gap-4">
                        {data.pools.map(pool => (
                            <div key={pool.id} className="bg-base-100 p-4 rounded-box border border-base-300 shadow-sm">
                                <div className="font-bold text-lg mb-2">{pool.month} / {pool.year}</div>
                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <div className="opacity-70"><Trans>Netto-Topf:</Trans></div>
                                    <div className="font-mono text-right">{formatMoney(pool.net_pool_cents)}</div>
                                    <div className="opacity-70"><Trans>Unique DLs:</Trans></div>
                                    <div className="font-mono text-right">{pool.total_unique_downloads}</div>
                                    <div className="opacity-70"><Trans>Shares gesamt:</Trans></div>
                                    <div className="font-mono text-right">{pool.total_shares}</div>
                                    <div className="opacity-70 font-bold border-t border-base-300 pt-1 mt-1"><Trans>Wert pro
                                        Share:</Trans>
                                    </div>
                                    <div
                                        className="font-mono text-right text-primary font-bold border-t border-base-300 pt-1 mt-1">{formatMoney(pool.value_per_share_cents)}</div>
                                </div>
                            </div>
                        ))}
                        {data.pools.length === 0 &&
                            <div className="opacity-50 text-sm"><Trans>Noch keine Pools berechnet.</Trans></div>}
                    </div>
                </div>

                <div className="xl:col-span-2">
                    <h2 className="font-bold text-xl mb-4"><Trans>Fotografen Statements</Trans></h2>
                    <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                        <table className="table table-zebra w-full table-sm">
                            <thead>
                            <tr>
                                <th><Trans>Zeitraum</Trans></th>
                                <th><Trans>Fotograf</Trans></th>
                                <th><Trans>Shares / Info</Trans></th>
                                <th className="text-right"><Trans>Auszahlung</Trans></th>
                                <th><Trans>Status / Aktion</Trans></th>
                            </tr>
                            </thead>
                            <tbody>
                            {data.statements.map(stmt => (
                                <tr key={stmt.id}>
                                    <td className="whitespace-nowrap font-mono">{stmt.month} / {stmt.year}</td>
                                    <td>
                                        <div className="font-bold">{stmt.user?.name || t`Unbekannt`}</div>
                                        <div className="text-sm opacity-70">{stmt.sequence_number}</div>
                                    </td>
                                    <td className="text-sm font-mono">
                                        {stmt.total_shares_earned} Shares<br/>
                                        <span
                                            className="opacity-50">Rollover: {formatMoney(stmt.rolled_over_amount_cents)}</span>
                                    </td>
                                    <td className="text-right font-mono font-bold text-base text-primary">
                                        {formatMoney(stmt.total_payable_cents)}
                                    </td>
                                    <td>
                                        <div className="flex flex-col gap-1 items-start">
                                            {statusBadge(stmt.status)}
                                            {stmt.status === 'pending' && (
                                                <button onClick={() => updateStatus(stmt.id, 'approve')}
                                                        className="btn btn-xs btn-outline mt-1"><Trans>Freigeben</Trans></button>
                                            )}
                                            {stmt.status === 'approved' && (
                                                <button onClick={() => updateStatus(stmt.id, 'pay')}
                                                        className="btn btn-xs btn-success text-white mt-1"><Trans>Als Bezahlt
                                                    markieren</Trans></button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {data.statements.length === 0 && <tr>
                                <td colSpan={5} className="text-center py-6 opacity-50"><Trans>Keine Statements vorhanden.</Trans></td>
                            </tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}
