import { useState } from 'react';
import { useAdminPayouts } from '../../logic/usePayouts';
import { formatMoney } from '../../logic/utils';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';

export default function ManagementPayoutsView() {
    const { data, isLoading, calculateMonth, updateStatus } = useAdminPayouts();
    const { showToast, confirm } = useUI();
    const [month, setMonth] = useState(new Date().getMonth() + 1);
    const [year, setYear] = useState(new Date().getFullYear());
    const [netPool, setNetPool] = useState('');
    const [isCalculating, setIsCalculating] = useState(false);

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (!data) return <div className="p-10"><ErrorMessage message="Fehler beim Laden der Payouts." /></div>;

    const handleCalculate = async (e: React.FormEvent) => {
        e.preventDefault();
        const cents = Math.round(parseFloat(netPool.replace(',', '.')) * 100);
        if (isNaN(cents) || cents < 0) {
            showToast('error', 'Bitte einen gültigen Betrag eingeben.');
            return;
        }
        if (!(await confirm({ title: 'Abrechnungslauf starten?', message: `Möchtest du den Pool für ${month}/${year} wirklich mit ${formatMoney(cents)} berechnen? Bestehende (nicht ausbezahlte) Statements für dieses Monat werden überschrieben.`, confirmText: 'Berechnen' }))) return;
        
        setIsCalculating(true);
        try {
            await calculateMonth(month, year, cents);
            showToast('success', 'Abrechnung erfolgreich durchgeführt!');
        } catch (error: unknown) {
            showToast('error', error instanceof Error ? error.message : 'Fehler bei der Berechnung');
        }
        setIsCalculating(false);
    };

    const statusBadge = (status: string) => {
        switch(status) {
            case 'pending': return <span className="badge badge-warning badge-sm">Wartet auf Freigabe</span>;
            case 'rollover': return <span className="badge badge-ghost badge-sm">&lt; 50€ (Rollover)</span>;
            case 'approved': return <span className="badge badge-info badge-sm">Freigegeben</span>;
            case 'paid': return <span className="badge badge-success badge-sm text-white">Ausbezahlt</span>;
            default: return <span className="badge badge-ghost badge-sm">{status}</span>;
        }
    };

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <h1 className="text-4xl font-bold mb-2">Abrechnungen (Payouts)</h1>
            <p className="opacity-70 mb-8">Verwalte Flatrate-Pools und Fotografen-Auszahlungen.</p>

            <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm mb-8">
                <h2 className="font-bold text-xl mb-4">Neuen Abrechnungslauf starten</h2>
                <form onSubmit={handleCalculate} className="flex flex-col md:flex-row items-end gap-4">
                    <div className="form-control w-full md:w-32">
                        <label className="label"><span className="label-text font-bold">Monat</span></label>
                        <input type="number" min="1" max="12" value={month} onChange={e => setMonth(parseInt(e.target.value))} className="input input-sm input-bordered" required />
                    </div>
                    <div className="form-control w-full md:w-32">
                        <label className="label"><span className="label-text font-bold">Jahr</span></label>
                        <input type="number" min="2024" value={year} onChange={e => setYear(parseInt(e.target.value))} className="input input-sm input-bordered" required />
                    </div>
                    <div className="form-control w-full md:w-48">
                        <label className="label">
                            <span className="label-text font-bold">Flatrate-Netto (€)</span>
                        </label>
                        <input type="number" step="0.01" value={netPool} onChange={e => setNetPool(e.target.value)} placeholder="z.B. 2500.00" className="input input-sm input-bordered font-mono" required />
                    </div>
                    <button type="submit" disabled={isCalculating} className="btn btn-primary btn-sm h-10 px-8 w-full md:w-auto">
                        {isCalculating ? <span className="loading loading-spinner"></span> : 'Berechnen'}
                    </button>
                </form>
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
                <div className="xl:col-span-1">
                    <h2 className="font-bold text-xl mb-4">Flatrate-Pools</h2>
                    <div className="flex flex-col gap-4">
                        {data.pools.map(pool => (
                            <div key={pool.id} className="bg-base-100 p-4 rounded-box border border-base-300 shadow-sm">
                                <div className="font-bold text-lg mb-2">{pool.month} / {pool.year}</div>
                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <div className="opacity-70">Netto-Topf:</div><div className="font-mono text-right">{formatMoney(pool.net_pool_cents)}</div>
                                    <div className="opacity-70">Unique DLs:</div><div className="font-mono text-right">{pool.total_unique_downloads}</div>
                                    <div className="opacity-70">Shares gesamt:</div><div className="font-mono text-right">{pool.total_shares}</div>
                                    <div className="opacity-70 font-bold border-t border-base-300 pt-1 mt-1">Wert pro Share:</div><div className="font-mono text-right text-primary font-bold border-t border-base-300 pt-1 mt-1">{formatMoney(pool.value_per_share_cents)}</div>
                                </div>
                            </div>
                        ))}
                        {data.pools.length === 0 && <div className="opacity-50 text-sm">Noch keine Pools berechnet.</div>}
                    </div>
                </div>

                <div className="xl:col-span-2">
                    <h2 className="font-bold text-xl mb-4">Fotografen Statements</h2>
                    <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                        <table className="table table-zebra w-full table-sm">
                            <thead>
                                <tr>
                                    <th>Zeitraum</th>
                                    <th>Fotograf</th>
                                    <th>Shares / Info</th>
                                    <th className="text-right">Auszahlung</th>
                                    <th>Status / Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.statements.map(stmt => (
                                    <tr key={stmt.id}>
                                        <td className="whitespace-nowrap font-mono">{stmt.month} / {stmt.year}</td>
                                        <td>
                                            <div className="font-bold">{stmt.user?.name || 'Unbekannt'}</div>
                                            <div className="text-xs opacity-70">{stmt.sequence_number}</div>
                                        </td>
                                        <td className="text-xs font-mono">
                                            {stmt.total_shares_earned} Shares<br/>
                                            <span className="opacity-50">Rollover: {formatMoney(stmt.rolled_over_amount_cents)}</span>
                                        </td>
                                        <td className="text-right font-mono font-bold text-base text-primary">
                                            {formatMoney(stmt.total_payable_cents)}
                                        </td>
                                        <td>
                                            <div className="flex flex-col gap-1 items-start">
                                                {statusBadge(stmt.status)}
                                                {stmt.status === 'pending' && (
                                                    <button onClick={() => updateStatus(stmt.id, 'approve')} className="btn btn-xs btn-outline mt-1">Freigeben</button>
                                                )}
                                                {stmt.status === 'approved' && (
                                                    <button onClick={() => updateStatus(stmt.id, 'pay')} className="btn btn-xs btn-success text-white mt-1">Als Bezahlt markieren</button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {data.statements.length === 0 && <tr><td colSpan={5} className="text-center py-6 opacity-50">Keine Statements vorhanden.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}
