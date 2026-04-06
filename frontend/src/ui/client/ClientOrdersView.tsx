import useSWR from 'swr';
import { fetcher } from '../../api';
import PageLayout from '../components/PageLayout';
import ErrorMessage from '../components/ErrorMessage';

interface InvoiceSnapshot {
    invoice_number: string;
    total_gross: string;
    created_at: string;
    customer_details: {
        items?: { filename: string, tier: string, price: number }[];
    };
}

interface Order {
    id: string;
    status: string;
    invoice_snapshot: InvoiceSnapshot;
}

export default function ClientOrdersView() {
    const { data: orders, error, isLoading } = useSWR<Order[]>('/api/orders', fetcher);

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
                            const date = new Date(snap?.created_at).toLocaleDateString('de-DE');
                            return (
                                <div key={order.id} className="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                                    <div className="bg-base-200/50 p-4 border-b border-base-300 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                        <div>
                                            <h2 className="font-bold text-lg">Bestellung vom {date}</h2>
                                            <p className="text-sm opacity-70">Rechnung: {snap?.invoice_number}</p>
                                        </div>
                                        <div className="flex items-center gap-4 w-full sm:w-auto">
                                            <div className="text-right flex-1 sm:flex-none">
                                                <div className="font-mono font-bold text-lg">{Number(snap?.total_gross).toFixed(2)} €</div>
                                            </div>
                                            <button className="btn btn-primary btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/download-zip', '_self')} title="Lizenzierte Bilder als ZIP herunterladen">
                                                <span className="iconify mdi--zip-box"></span> Bilder ZIP
                                            </button>
                                            <button className="btn btn-outline btn-sm shrink-0" onClick={() => window.open('/api/orders/' + order.id + '/invoice', '_self')} title="Rechnung als PDF herunterladen">
                                                <span className="iconify mdi--file-pdf-box text-error"></span> Beleg
                                            </button>
                                        </div>
                                    </div>
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
