import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';

interface Order {
    id: string;
    status: string;
    total_amount: string;
    created_at: string;
    user: { name: string; email: string };
    invoice_snapshot: { invoice_number: string };
}

export default function ManagementOrdersView() {
    const { data: orders, error, isLoading, mutate } = useSWR<Order[]>('/api/management/orders', fetcher);
    const { showToast } = useUI();

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

    const statusLabels: Record<string, { label: string, color: string }> = {
        'invoice_created': { label: 'Offen / Ausstehend', color: 'badge-warning' },
        'paid': { label: 'Bezahlt', color: 'badge-success text-white' },
        'overdue': { label: 'Überfällig', color: 'badge-error text-white' },
        'cancelled': { label: 'Storniert', color: 'badge-neutral' }
    };

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full relative">
            <h1 className="text-4xl font-bold mb-8">Bestellungen & Rechnungen</h1>

            <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Rechnungsnummer</th>
                            <th>Kunde</th>
                            <th>Betrag</th>
                            <th>Zahlungsstatus</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders?.map(order => (
                            <tr key={order.id}>
                                <td className="whitespace-nowrap text-sm">{new Date(order.created_at).toLocaleDateString('de-DE')}</td>
                                <td className="font-mono text-sm font-bold">{order.invoice_snapshot?.invoice_number}</td>
                                <td>
                                    <div className="font-bold">{order.user?.name}</div>
                                    <div className="text-xs opacity-70">{order.user?.email}</div>
                                </td>
                                <td className="font-mono">{Number(order.total_amount).toFixed(2)} €</td>
                                <td>
                                    <select 
                                        className={`select select-sm select-bordered ${statusLabels[order.status]?.color?.replace('badge', 'text').replace('text-white', '')}`}
                                        value={order.status}
                                        onChange={(e) => handleStatusChange(order.id, e.target.value)}
                                    >
                                        <option value="invoice_created">Offen / Ausstehend</option>
                                        <option value="paid">Bezahlt</option>
                                        <option value="overdue">Überfällig</option>
                                        <option value="cancelled">Storniert</option>
                                    </select>
                                </td>
                            </tr>
                        ))}
                        {orders?.length === 0 && <tr><td colSpan={5} className="text-center py-10 opacity-50">Noch keine Bestellungen im System.</td></tr>}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
