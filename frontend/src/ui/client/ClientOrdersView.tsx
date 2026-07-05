import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useBillingDetails } from '../../logic/useLicenseTerms';
import useSWR from 'swr';
import { fetcher } from '../../api';
import PageLayout from '../components/PageLayout';
import ErrorMessage from '../components/ErrorMessage';
import { Order, OrderItem } from '../../api';
import { formatMoney } from '../../logic/utils';



export default function ClientOrdersView() {
    const { data: orders, error, isLoading } = useSWR<Order[]>('/api/orders', fetcher);
    const { billingDetails } = useBillingDetails();

    if (isLoading) return <PageLayout currentView="orders"><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (error) return <PageLayout currentView="orders"><div className="p-8"><ErrorMessage message={t`Fehler beim Laden der Einkäufe.`} /></div></PageLayout>;

    return (
        <PageLayout currentView="orders">
            <div className="container mx-auto p-4 md:p-8 max-w-5xl">
                <h1 className="text-3xl font-bold mb-2 flex items-center gap-2">
                    <span className="iconify mdi--license text-primary"></span> <Trans>Meine Einkäufe & Lizenzen</Trans>
                </h1>
                <p className="opacity-70 mb-8"><Trans>Hier findest du alle deine lizenzierten Bilder und die dazugehörigen Rechnungen.</Trans></p>

                {(!orders || orders.length === 0) ? (
                    <div className="alert shadow-sm bg-base-100 border border-base-300">
                        <span className="iconify mdi--information text-xl"></span>
                        <span><Trans>Du hast bisher keine kostenpflichtigen Lizenzen erworben.</Trans></span>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {orders.map(order => {
                            const snap = order.invoice_snapshot;
                            const date = snap?.created_at ? new Date(snap.created_at).toLocaleDateString('de-DE') : '';
                            const isQuote = order.is_quote_request;
                            const isPendingQuote = isQuote && order.status === 'pending';
                            const isBlocked = ['disputed', 'refunded', 'cancelled'].includes(order.status);
                            const orderStatus = order.status;
                            return (
                                <div key={order.id} className="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                                    <div className="bg-base-200/50 p-4 border-b border-base-300 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                        <div>
                                            <h2 className="font-bold text-lg">{isQuote ? <Trans>Angebotsanfrage</Trans> : <Trans>Bestellung</Trans>} vom {date}</h2>
                                            <p className="text-sm opacity-70">{isQuote ? t`Anfrage` : t`Rechnung`}: {snap?.invoice_number}</p>
                                        </div>
                                        <div className="flex items-center gap-4 w-full sm:w-auto">
                                            <div className="text-right flex-1 sm:flex-none">
                                                <div className="font-mono font-bold text-lg text-primary">{isQuote ? '--- €' : formatMoney(Number(snap?.total_gross))}</div>
                                            </div>
{isPendingQuote ? <span className="badge badge-warning font-bold p-3"><Trans>Angebot ausständig</Trans></span> : 
isBlocked ? <span className="badge badge-error font-bold p-3"><Trans>Zugriff gesperrt ({orderStatus})</Trans></span> :
                                            <>                                            <button className="btn btn-primary btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/download-zip', '_blank')} title={t`Lizenzierte Bilder als ZIP herunterladen`}>
                                                <span className="iconify mdi--zip-box"></span> <Trans>Bilder ZIP</Trans>
                                            </button>
                                            <button className="btn btn-outline btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/invoice', '_blank')} title={t`Rechnung als PDF herunterladen`}>
                                                <span className="iconify mdi--file-pdf-box text-error"></span> <Trans>Beleg</Trans>
                                            </button></>}
                                        </div>
                                    </div>
                                    {order.status === 'invoice_created' && (
                                        <div className="bg-warning/10 border-y border-warning/20 p-4 text-sm">
                                            <p className="font-bold mb-1 flex items-center gap-2"><span className="iconify mdi--bank text-warning"></span> <Trans>Zahlung ausständig (Kauf auf Rechnung)</Trans></p>
                                            <p className="opacity-80 mb-2"><Trans>Bitte überweise den Rechnungsbetrag zeitnah. Gib als Verwendungszweck die Belegnummer an.</Trans></p>
                                            <div className="font-mono text-sm opacity-90 bg-base-100 p-2 rounded inline-block shadow-sm">
                                                <div><Trans>Empfänger:</Trans> <strong>{billingDetails?.bank_holder}</strong></div>
                                                <div>IBAN: <strong>{billingDetails?.bank_iban}</strong></div>
                                                <div>BIC: <strong>{billingDetails?.bank_bic}</strong></div>
                                            </div>
                                        </div>
                                    )}
                                    <div className="p-0">
                                        <table className="table table-sm w-full">
                                            <thead className="bg-base-100">
                                                <tr>
                                                    <th><Trans>Datei</Trans></th>
                                                    <th><Trans>Lizenz (Auflösung)</Trans></th>
                                                    <th className="text-right"><Trans>Preis</Trans></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(typeof snap?.customer_details === 'object' && snap?.customer_details?.items) ? snap?.customer_details?.items?.map((item: OrderItem, idx: number) => (
                                                    <tr key={idx}>
                                                        <td className="font-mono text-sm">{item.filename}</td>
                                                        <td><span className="badge badge-ghost badge-sm">{item.tier?.toUpperCase() ?? ''}</span></td>
                                                        <td className="text-right font-mono text-sm">{formatMoney(Number(item.price))}</td>
                                                    </tr>
                                                )) : null}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
