import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';

export interface Customer {
    id: string;
    name: string;
    company?: string | null;
    email?: string | null;
    street?: string | null;
    zip?: string | null;
    city?: string | null;
    country?: string | null;
    uid?: string | null;
}

export default function ManagementCustomersView() {
    const { data: customers, error, isLoading, mutate } = useSWR<Customer[]>('/api/management/customers', fetcher);
    const { showToast, confirm } = useUI();
    const [searchTerm, setSearchTerm] = useState('');

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: 'Kunde löschen?', message: 'Möchtest du diesen Kunden wirklich aus dem CRM entfernen?', confirmColor: 'error' }))) return;
        try {
            await apiMutate(`/api/management/customers/${id}`, 'DELETE');
            mutate();
            showToast('success', 'Kunde gelöscht');
        } catch {
            showToast('error', 'Fehler beim Löschen');
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message="Fehler beim Laden der Kunden." /></div>;

    const filtered = customers?.filter(c => 
        (c.name || '').toLowerCase().includes(searchTerm.toLowerCase()) || 
        (c.company || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
        (c.email || '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2">Kunden (CRM)</h1>
                    <p className="opacity-70">Verwalte deine B2B-Kontakte für Rechnungen und Angebote.</p>
                </div>
                <button className="btn btn-primary" onClick={() => showToast('info', 'Funktion zum Anlegen folgt in Kürze (CRUD-Modal)')}>+ Neuer Kunde</button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm">
                <input 
                    type="text" 
                    placeholder="Kunden suchen..." 
                    className="input input-bordered w-full md:w-1/2 mb-6" 
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                />

                <div className="overflow-x-auto">
                    <table className="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Name / Firma</th>
                                <th>E-Mail</th>
                                <th>Ort</th>
                                <th className="text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered?.map(c => (
                                <tr key={c.id}>
                                    <td>
                                        <div className="font-bold">{c.company || '-'}</div>
                                        <div className="text-xs opacity-70">{c.name}</div>
                                    </td>
                                    <td>{c.email || <span className="opacity-30 italic">Keine E-Mail</span>}</td>
                                    <td>{c.zip} {c.city}</td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <button className="btn btn-ghost btn-xs btn-square" title="Bearbeiten"><span className="iconify mdi--pencil text-base"></span></button>
                                            <button className="btn btn-ghost btn-xs btn-square text-error" onClick={() => handleDelete(c.id)} title="Löschen"><span className="iconify mdi--trash-can text-base"></span></button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {filtered?.length === 0 && (
                                <tr><td colSpan={4} className="text-center py-10 opacity-50">Keine Kunden gefunden.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}