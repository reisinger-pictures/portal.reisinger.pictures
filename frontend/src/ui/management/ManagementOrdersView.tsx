import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import { Order, OrderItem } from '../../api';
import { formatMoney } from '../../logic/utils';



export default function ManagementOrdersView() {
    const { data: orders, error, isLoading, mutate } = useSWR<Order[]>('/api/management/orders', fetcher);
    const { showToast } = useUI();

    const [quoteOrder, setQuoteOrder] = useState<Order | null>(null);
    const [customPrice, setCustomPrice] = useState<string>('');
    const [quoteMessage, setQuoteMessage] = useState<string>('');
    const [isGenerating, setIsGenerating] = useState(false);

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10 text-error">Fehler beim Laden der Bestellungen.</div>;

    const handleStatusChange = async (id: string, newStatus: string) => {
        try {
            await apiMutate(`/api/management/orders/${id}/status`, 'PUT', { status: newStatus });
            showToast('success', 'Status aktualisiert');
            mutate();
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    const handleSendQuote = async () => {
        if (!quoteOrder || !customPrice || !quoteMessage) return;
        setIsGenerating(true);
        try {
            await apiMutate(`/api/management/orders/${quoteOrder.id}/send-quote`, 'POST', {
                custom_price: Math.round(parseFloat(customPrice.replace(',', '.')) * 100),
                message: quoteMessage
            });
            showToast('success', 'Angebot per E-Mail gesendet!');
            setQuoteOrder(null);
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Senden');
        } finally {
            setIsGenerating(false);
        }
    };

    const statusLabels: Record<string, { label: string, color: string }> = {
        'pending': { label: 'Ausständig / Angebot', color: 'badge-neutral' },
        'invoice_created': { label: 'Offen / Rechnung', color: 'badge-warning' },
        'paid': { label: 'Bezahlt', color: 'badge-success text-white' },
        'overdue': { label: 'Überfällig', color: 'badge-error text-white' },
        'cancelled': { label: 'Storniert', color: 'badge-neutral' }
    };

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full relative">
            <h1 className="text-4xl font-bold mb-8">Bestellungen & Anfragen</h1>

            <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Beleg / Typ</th>
                            <th>Kunde</th>
                            <th>Betrag</th>
                            <th>Status / Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders?.map(order => (
                            <tr key={order.id}>
                                <td className="whitespace-nowrap text-sm">{new Date(order.created_at).toLocaleDateString('de-DE')}</td>
                                <td>
                                    <div className="font-mono text-sm font-bold">{order.invoice_snapshot?.invoice_number}</div>
                                    {order.is_quote_request ? <div className="badge badge-info badge-sm mt-1">Angebot</div> : null}
                                </td>
                                <td>
                                    <div className="font-bold">{order.user?.name}</div>
                                    <div className="text-sm opacity-70">{order.user?.email}</div>
                                </td>
                                <td className="font-mono">{(order.is_quote_request ? true : false) && order.status === 'pending' ? 'Auf Anfrage' : formatMoney(Number(order.total_gross))}</td>
                                <td>
                                    <div className="flex flex-col gap-2 items-start">
                                        <select 
                                            className={`select select-sm select-bordered ${statusLabels[order.status]?.color?.replace('badge', 'text').replace('text-white', '')}`}
                                            value={order.status}
                                            onChange={(e) => handleStatusChange(order.id, e.target.value)}
                                        >
                                            <option value="pending">Ausständig / Angebot</option>
                                            <option value="invoice_created">Offen / Rechnung</option>
                                            <option value="paid">Bezahlt</option>
                                            <option value="overdue">Überfällig</option>
                                            <option value="cancelled">Storniert</option>
                                        </select>
                                        {(order.is_quote_request ? true : false) && order.status === 'pending' ? (
                                            <button onClick={() => { setQuoteOrder(order); setCustomPrice(''); setQuoteMessage(''); }} className="btn btn-xs btn-primary">
                                                Kalkulieren & Antworten
                                            </button>
                                        ) : null}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {orders?.length === 0 && <tr><td colSpan={5} className="text-center py-10 opacity-50">Noch keine Bestellungen im System.</td></tr>}
                    </tbody>
                </table>
            </div>

            {quoteOrder && (
                <div className="modal modal-open z-50">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setQuoteOrder(null)}>✕</button>
                        <h3 className="font-bold text-xl mb-4">Angebot kalkulieren & senden</h3>
                        <p className="text-sm opacity-80 mb-4">Lege einen Gesamtpreis für die angefragten Bilder fest und verfasse eine Nachricht an den Kunden.</p>

                        <div className="bg-base-200 p-4 rounded-box mb-4 text-sm max-h-40 overflow-y-auto">
                            <strong className="block mb-2">Anforderungen des Kunden:</strong>
                            <div className="opacity-70 mb-2 italic">{(typeof quoteOrder.invoice_snapshot?.customer_details === 'object' ? quoteOrder.invoice_snapshot?.customer_details?.quote_message : null) || 'Keine generelle Nachricht'}</div>
                            {(typeof quoteOrder.invoice_snapshot?.customer_details === 'object' ? (quoteOrder.invoice_snapshot?.customer_details?.items || []) : []).map((item: OrderItem, idx: number) => (
                                <div key={idx} className="mb-2 pb-2 border-b border-base-300 last:border-0 last:mb-0 last:pb-0">
                                    <div className="font-mono font-bold">{item.filename}</div>
                                    <div className="opacity-70">{item.notes || '-'}</div>
                                </div>
                            ))}
                        </div>

                        <div className="form-control mb-4">
                            <label className="label"><span className="label-text font-bold">Pauschalpreis (Netto in €)</span></label>
                            <input type="number" step="0.01" value={customPrice} onChange={e => setCustomPrice(e.target.value)} className="input input-bordered w-full font-mono" placeholder="z.B. 450.00" autoFocus />
                        </div>

                        <div className="form-control mb-4">
                            <label className="label"><span className="label-text font-bold">Nachricht an den Kunden</span></label>
                            <textarea value={quoteMessage} onChange={e => setQuoteMessage(e.target.value)} className="textarea textarea-bordered w-full h-24" placeholder="Hallo, hier ist mein Angebot für Ihre speziellen Rechte..."></textarea>
                        </div>

                        <div className="modal-action col-span-full">
                            <button className="btn btn-ghost" onClick={() => setQuoteOrder(null)}>Abbrechen</button>
                            <button className="btn btn-primary" onClick={handleSendQuote} disabled={!customPrice || !quoteMessage || isGenerating}>
                                {isGenerating ? <span className="loading loading-spinner"></span> : 'Kalkulieren & E-Mail senden'}
                            </button>
                        </div>
                    </div>
                    <div className="modal-backdrop" onClick={() => setQuoteOrder(null)}></div>
                </div>
            )}
        </div>
    );
}