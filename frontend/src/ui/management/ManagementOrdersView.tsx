import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import { Order, OrderItem } from '../../api';
import { formatMoney } from '../../logic/utils';



export default function ManagementOrdersView() {
    const { data: response, error, isLoading, mutate } = useSWR<{ data: Order[] } | Order[]>('/api/management/orders', fetcher);
    const orders = Array.isArray(response) ? response : response?.data;
    const { showToast } = useUI();

    const [quoteOrder, setQuoteOrder] = useState<Order | null>(null);
    const [customPrice, setCustomPrice] = useState<string>('');
    const [quoteMessage, setQuoteMessage] = useState<string>('');
    const [isGenerating, setIsGenerating] = useState(false);

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10 text-error"><Trans>Fehler beim Laden der Bestellungen.</Trans></div>;

    const handleStatusChange = async (id: string, newStatus: string) => {
        try {
            await apiMutate(`/api/management/orders/${id}/status`, 'PUT', { status: newStatus });
            showToast('success', t`Status aktualisiert`);
            mutate();
        } catch {
            showToast('error', t`Fehler beim Speichern`);
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
            showToast('success', t`Angebot per E-Mail gesendet!`);
            setQuoteOrder(null);
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Senden`);
        } finally {
            setIsGenerating(false);
        }
    };

    const statusLabels: Record<string, { label: string, color: string, textColor: string }> = {
        'pending': { label: t`Ausständig / Angebot`, color: 'badge-neutral', textColor: 'text-neutral' },
        'invoice_created': { label: t`Offen / Rechnung`, color: 'badge-warning', textColor: 'text-warning' },
        'paid': { label: t`Bezahlt`, color: 'badge-success text-white', textColor: 'text-success' },
        'overdue': { label: t`Überfällig`, color: 'badge-error text-white', textColor: 'text-error' },
        'cancelled': { label: t`Storniert`, color: 'badge-neutral', textColor: 'text-neutral' }
    };

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full relative">
            <h1 className="text-4xl font-bold mb-8"><Trans>Bestellungen & Anfragen</Trans></h1>

            <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th><Trans>Datum</Trans></th>
                            <th><Trans>Beleg / Typ</Trans></th>
                            <th><Trans>Kunde</Trans></th>
                            <th><Trans>Betrag</Trans></th>
                            <th><Trans>Status / Aktion</Trans></th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders?.map(order => (
                            <tr key={order.id}>
                                <td className="whitespace-nowrap text-sm">{new Date(order.created_at).toLocaleDateString('de-DE')}</td>
                                <td>
                                    <div className="font-mono text-sm font-bold">{order.invoice_snapshot?.invoice_number}</div>
                                    {order.is_quote_request ? <div className="badge badge-info badge-sm mt-1"><Trans>Angebot</Trans></div> : null}
                                </td>
                                <td>
                                    <div className="font-bold">{order.user?.name}</div>
                                    <div className="text-sm opacity-70">{order.user?.email}</div>
                                </td>
                                <td className="font-mono">{order.is_quote_request && order.status === 'pending' ? <Trans>Auf Anfrage</Trans> : formatMoney(Number(order.total_gross))}</td>
                                <td>
                                    <div className="flex flex-col gap-2 items-start">
                                        <select 
                                            className={`select select-sm select-bordered ${statusLabels[order.status]?.textColor || ''}`}
                                            value={order.status}
                                            onChange={(e) => handleStatusChange(order.id, e.target.value)}
                                        >
                                            <option value="pending"><Trans>Ausständig / Angebot</Trans></option>
                                            <option value="invoice_created"><Trans>Offen / Rechnung</Trans></option>
                                            <option value="paid"><Trans>Bezahlt</Trans></option>
                                            <option value="overdue"><Trans>Überfällig</Trans></option>
                                            <option value="cancelled"><Trans>Storniert</Trans></option>
                                        </select>
                                        {order.is_quote_request && order.status === 'pending' ? (
                                            <button onClick={() => { setQuoteOrder(order); setCustomPrice(''); setQuoteMessage(''); }} className="btn btn-xs btn-primary">
                                                <Trans>Kalkulieren & Antworten</Trans>
                                            </button>
                                        ) : null}
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {orders?.length === 0 && <tr><td colSpan={5} className="text-center py-10 opacity-50"><Trans>Noch keine Bestellungen im System.</Trans></td></tr>}
                    </tbody>
                </table>
            </div>

            {quoteOrder && (
                <div className="modal modal-open z-50">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setQuoteOrder(null)}>✕</button>
                        <h3 className="font-bold text-xl mb-4"><Trans>Angebot kalkulieren & senden</Trans></h3>
                        <p className="text-sm opacity-80 mb-4"><Trans>Lege einen Gesamtpreis für die angefragten Bilder fest und verfasse eine Nachricht an den Kunden.</Trans></p>

                        <div className="bg-base-200 p-4 rounded-box mb-4 text-sm max-h-40 overflow-y-auto">
                            <strong className="block mb-2"><Trans>Anforderungen des Kunden:</Trans></strong>
                            <div className="opacity-70 mb-2 italic">{(typeof quoteOrder.invoice_snapshot?.customer_details === 'object' ? quoteOrder.invoice_snapshot?.customer_details?.quote_message : null) || t`Keine generelle Nachricht`}</div>
                            {(typeof quoteOrder.invoice_snapshot?.customer_details === 'object' ? (quoteOrder.invoice_snapshot?.customer_details?.items || []) : []).map((item: OrderItem, idx: number) => (
                                <div key={idx} className="mb-2 pb-2 border-b border-base-300 last:border-0 last:mb-0 last:pb-0">
                                    <div className="font-mono font-bold">{item.filename}</div>
                                    <div className="opacity-70">{item.notes || '-'}</div>
                                </div>
                            ))}
                        </div>

                        <div className="form-control mb-4">
                            <label className="label"><span className="label-text font-bold"><Trans>Pauschalpreis (Netto in €)</Trans></span></label>
                            <input type="number" step="0.01" value={customPrice} onChange={e => setCustomPrice(e.target.value)} className="input input-bordered w-full font-mono"                                 placeholder={t`z.B. 450.00`} autoFocus />
                        </div>

                        <div className="form-control mb-4">
                            <label className="label"><span className="label-text font-bold"><Trans>Nachricht an den Kunden</Trans></span></label>
                            <textarea value={quoteMessage} onChange={e => setQuoteMessage(e.target.value)} className="textarea textarea-bordered w-full h-24"                                 placeholder={t`Hallo, hier ist mein Angebot für Ihre speziellen Rechte...`}></textarea>
                        </div>

                        <div className="modal-action col-span-full">
                            <button className="btn btn-ghost" onClick={() => setQuoteOrder(null)}><Trans>Abbrechen</Trans></button>
                            <button className="btn btn-primary" onClick={handleSendQuote} disabled={!customPrice || !quoteMessage || isGenerating}>
                                {isGenerating ? <span className="loading loading-spinner"></span> : <Trans>Kalkulieren & E-Mail senden</Trans>}
                            </button>
                        </div>
                    </div>
                    <div className="modal-backdrop" onClick={() => setQuoteOrder(null)}></div>
                </div>
            )}
        </div>
    );
}