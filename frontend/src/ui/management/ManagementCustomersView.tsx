import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import CustomerModal from './components/CustomerModal';
import { Customer } from '../../api';



export default function ManagementCustomersView() {
    const { data: customers, error, isLoading, mutate } = useSWR<Customer[]>('/api/management/customers', fetcher);
    const { showToast, confirm } = useUI();
    const [searchTerm, setSearchTerm] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingCustomer, setEditingCustomer] = useState<Customer | null>(null);

    const handleSave = async (data: Partial<Customer>) => {
        try {
            if (editingCustomer) {
                await apiMutate(`/api/management/customers/${editingCustomer.id}`, 'PUT', data);
                showToast('success', t`Kunde aktualisiert`);
            } else {
                await apiMutate('/api/management/customers', 'POST', data);
                showToast('success', t`Kunde angelegt`);
            }
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Speichern`);
        }
    };

    const openEdit = (c: Customer) => {
        setEditingCustomer(c);
        setIsModalOpen(true);
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: t`Kunde löschen?`, message: t`Möchtest du diesen Kunden wirklich aus dem CRM entfernen?`, confirmColor: 'error' }))) return;
        try {
            await apiMutate(`/api/management/customers/${id}`, 'DELETE');
            mutate();
            showToast('success', t`Kunde gelöscht`);
        } catch {
            showToast('error', t`Fehler beim Löschen`);
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden der Kunden.`} /></div>;

    const filtered = customers?.filter(c => 
        (c.name || '').toLowerCase().includes(searchTerm.toLowerCase()) || 
        (c.company || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
        (c.email || '').toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2"><Trans>Kunden (CRM)</Trans></h1>
                    <p className="opacity-70"><Trans>Verwalte deine Geschäftskontakte für Rechnungen und Angebote.</Trans></p>
                </div>
                <button className="btn btn-primary" onClick={() => { setEditingCustomer(null); setIsModalOpen(true); }}>+ <Trans>Neuer Kunde</Trans></button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm">
                <input 
                    type="text" 
                    placeholder={t`Kunden suchen...`} 
                    className="input input-bordered w-full md:w-1/2 mb-6" 
                    value={searchTerm}
                    onChange={e => setSearchTerm(e.target.value)}
                />

                <div className="overflow-x-auto">
                    <table className="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th><Trans>Name / Firma</Trans></th>
                                <th><Trans>E-Mail</Trans></th>
                                <th><Trans>Ort</Trans></th>
                                <th className="text-right"><Trans>Aktionen</Trans></th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered?.map(c => (
                                <tr key={c.id}>
                                    <td>
                                        <div className="font-bold">{c.company || '-'}</div>
                                        <div className="text-sm opacity-70">{c.name}</div>
                                    </td>
                                    <td>{c.email || <span className="opacity-30 italic"><Trans>Keine E-Mail</Trans></span>}</td>
                                    <td>{c.zip} {c.city}</td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-2">
                                            <button className="btn btn-ghost btn-xs btn-square" title={t`Bearbeiten`} onClick={() => openEdit(c)}><span className="iconify mdi--pencil text-base"></span></button>
                                            <button className="btn btn-ghost btn-xs btn-square text-error" onClick={() => handleDelete(c.id)} title={t`Löschen`}><span className="iconify mdi--trash-can text-base"></span></button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {filtered?.length === 0 && (
                                <tr><td colSpan={4} className="text-center py-10 opacity-50"><Trans>Keine Kunden gefunden.</Trans></td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            <CustomerModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} editingCustomer={editingCustomer} onSave={handleSave} />
        </div>
    );
}