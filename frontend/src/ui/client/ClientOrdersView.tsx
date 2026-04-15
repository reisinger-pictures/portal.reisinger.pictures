import { useLicenseTerms } from '../../logic/useLicenseTerms';
import useSWR from 'swr';
import { fetcher } from '../../api';
import PageLayout from '../components/PageLayout';
import ErrorMessage from '../components/ErrorMessage';
import { Order } from '../../api';



export default function ClientOrdersView() {
    const { data: orders, error, isLoading } = useSWR<Order[]>('/api/orders', fetcher);
    const { terms } = useLicenseTerms();

    if (isLoading) return <PageLayout currentView="orders"><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (error) return <PageLayout currentView="orders"><div className="p-8"><ErrorMessage message="Fehler beim Laden der Einkäufe." /></div></PageLayout>;

    return (
        <PageLayout currentView="orders">
            <div className="container mx-auto p-4 md:p-8 max-w-5xl">
                <h1 className="text-3xl font-bold mb-2 flex items-center gap-2">
                    <span className="iconify mdi--license text-primary"></span> Meine Einkäufe & Lizenzen
                </h1>
                <p className="opacity-70 mb-8">Hier findest du alle deine lizenzierten Bilder und die dazugehörigen Rechnungen.</p>

                {(!orders || orders.length === 0) ? (
                    <div className="alert shadow-sm bg-base-100 border border-base-300">
                        <span className="iconify mdi--information text-xl"></span>
                        <span>Du hast bisher keine kostenpflichtigen Lizenzen erworben.</span>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {orders.map(order => {
                            const snap = order.invoice_snapshot;
                            const date = snap?.created_at ? new Date(snap.created_at).toLocaleDateString('de-DE') : '';
                            const isQuote = order.is_quote_request;
                            const isPendingQuote = isQuote && order.status === 'pending';
                            const isBlocked = ['disputed', 'refunded', 'cancelled'].includes(order.status);
                            return (
                                <div key={order.id} className="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                                    <div className="bg-base-200/50 p-4 border-b border-base-300 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                        <div>
                                            <h2 className="font-bold text-lg">{isQuote ? 'Angebotsanfrage' : 'Bestellung'} vom {date}</h2>
                                            <p className="text-sm opacity-70">{isQuote ? 'Anfrage' : 'Rechnung'}: {snap?.invoice_number}</p>
                                        </div>
                                        <div className="flex items-center gap-4 w-full sm:w-auto">
                                            <div className="text-right flex-1 sm:flex-none">
                                                <div className="font-mono font-bold text-lg text-primary">{isQuote ? '--- €' : `${Number(snap?.total_gross).toFixed(2)} €`}</div>
                                            </div>
                                            {isPendingQuote ? <span className="badge badge-warning font-bold p-3">Angebot ausständig</span> : 
                                            isBlocked ? <span className="badge badge-error font-bold p-3">Zugriff gesperrt ({order.status})</span> :
                                            <><button className="btn btn-primary btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/download-zip', '_self')} title="Lizenzierte Bilder als ZIP herunterladen">
                                                <span className="iconify mdi--zip-box"></span> Bilder ZIP
                                            </button>
                                            <button className="btn btn-outline btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/invoice', '_self')} title="Rechnung als PDF herunterladen">
                                                <span className="iconify mdi--file-pdf-box text-error"></span> Beleg
                                            </button></>}
                                        </div>
                                    </div>
                                    {order.status === 'invoice_created' && (
                                        <div className="bg-warning/10 border-y border-warning/20 p-4 text-sm">
                                            <p className="font-bold mb-1 flex items-center gap-2"><span className="iconify mdi--bank text-warning"></span> Zahlung ausständig (Kauf auf Rechnung)</p>
                                            <p className="opacity-80 mb-2">Bitte überweise den Rechnungsbetrag zeitnah. Gib als Verwendungszweck die Belegnummer an.</p>
                                            <div className="font-mono text-xs opacity-90 bg-base-100 p-2 rounded inline-block shadow-sm">
                                                <div>Empfänger: <strong>{terms?.bank_holder}</strong></div>
                                                <div>IBAN: <strong>{terms?.bank_iban}</strong></div>
                                                <div>BIC: <strong>{terms?.bank_bic}</strong></div>
                                            </div>
                                        </div>
                                    )}
                                    <div className="p-0">
                                        <table className="table table-sm w-full">
                                            <thead className="bg-base-100">
                                                <tr>
                                                    <th>Datei</th>
                                                    <th>Lizenz (Auflösung)</th>
                                                    <th className="text-right">Preis</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {snap?.customer_details?.items?.map((item, idx) => (
                                                    <tr key={idx}>
                                                        <td className="font-mono text-xs">{item.filename}</td>
                                                        <td><span className="badge badge-ghost badge-sm">{item.tier.toUpperCase()}</span></td>
                                                        <td className="text-right font-mono text-xs">{Number(item.price).toFixed(2)} €</td>
                                                    </tr>
                                                ))}
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
