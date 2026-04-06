import { useState } from 'react';
import { useTenants } from '../../logic/useTenants';
import { useUI } from '../components/UIContext';
import { Link, useNavigate } from 'react-router-dom';
import PageLayout from '../components/PageLayout';

export default function ManagementTenantsView() {
    const { tenants, createTenant, isLoading } = useTenants();
    const { showToast } = useUI();
    const navigate = useNavigate();

    const [isCreateOpen, setCreateOpen] = useState(false);
    const [newName, setNewName] = useState('');
    const [newDomain, setNewDomain] = useState('');
    const [isCreating, setIsCreating] = useState(false);

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsCreating(true);
        try {
            await createTenant({ name: newName, domain: newDomain || null, invoice_frequency: 'immediate' });
            showToast('success', 'Mandant erstellt');
            setCreateOpen(false);
            setNewName('');
            setNewDomain('');
        } catch (err: any) {
            showToast('error', err.message || 'Fehler beim Erstellen');
        } finally {
            setIsCreating(false);
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <PageLayout currentView="tenants">
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full relative">
            <div className="flex justify-between items-center mb-8">
                <div>
                    <h1 className="text-4xl font-bold mb-2">Mandanten (B2B)</h1>
                    <p className="opacity-70">Verwalte Organisationen, deren Mitarbeiter und Sammelrechnungen.</p>
                </div>
                <button className="btn btn-primary" onClick={() => setCreateOpen(true)}>+ Neuer Mandant</button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {tenants?.map(t => (
                    <div key={t.id} className="card bg-base-100 border border-base-300 shadow-sm hover:shadow-md transition-shadow cursor-pointer" onClick={() => navigate(`/tenants/${t.id}`)}>
                        <div className="card-body p-5">
                            <h2 className="card-title text-xl text-primary">{t.name}</h2>
                            {t.domain ? <code className="text-xs bg-base-200 p-1 rounded">@{t.domain}</code> : <span className="text-xs opacity-50 italic">Keine Auto-Join Domain</span>}
                            
                            <div className="flex gap-4 mt-4 text-sm opacity-80">
                                <div className="flex items-center gap-1"><span className="iconify mdi--account-group"></span> {t.users_count || 0} User</div>
                                <div className="flex items-center gap-1"><span className="iconify mdi--folder-multiple"></span> {t.gallery_groups_count || 0} Ordner</div>
                            </div>
                        </div>
                    </div>
                ))}
                {tenants?.length === 0 && (
                    <div className="col-span-full py-12 text-center opacity-50 bg-base-200 rounded-box border border-base-300">
                        <span className="iconify mdi--domain text-4xl mb-2"></span>
                        <p>Noch keine Mandanten angelegt.</p>
                    </div>
                )}
            </div>

            {isCreateOpen && (
                <div className="modal modal-open">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setCreateOpen(false)}>✕</button>
                        <h3 className="font-bold text-lg mb-4">Neuen Mandanten anlegen</h3>
                        <form onSubmit={handleCreate} className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Name (z.B. Firma XYZ)</span></label>
                                <input type="text" required value={newName} onChange={e => setNewName(e.target.value)} className="input input-bordered" />
                            </div>
                            <div className="form-control">
                                <label className="label">
                                    <span className="label-text font-bold">Auto-Join Domain (Optional)</span>
                                    <span className="label-text-alt opacity-70">ohne @</span>
                                </label>
                                <input type="text" value={newDomain} onChange={e => setNewDomain(e.target.value)} placeholder="firma.de" className="input input-bordered font-mono" />
                            </div>
                            <div className="modal-action">
                                <button type="button" className="btn btn-ghost" onClick={() => setCreateOpen(false)}>Abbrechen</button>
                                <button type="submit" className="btn btn-primary" disabled={isCreating}>Speichern</button>
                            </div>
                        </form>
                    </div>
                    <div className="modal-backdrop" onClick={() => setCreateOpen(false)}></div>
                </div>
            )}
        </div>
        </PageLayout>
    );
}
