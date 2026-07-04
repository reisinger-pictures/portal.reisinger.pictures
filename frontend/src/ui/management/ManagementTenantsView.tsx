import { useState } from 'react';
import { useTenants } from '../../logic/useTenants';
import { useUI } from '../components/UIContext';
import { useNavigate } from 'react-router-dom';
import { usePermissions } from '../../logic/usePermissions';
import PageLayout from '../components/PageLayout';
import EmptyState from '../components/EmptyState';

export default function ManagementTenantsView() {
    const { tenants, createTenant, isLoading } = useTenants();
    const { showToast } = useUI();
    const { isAdmin } = usePermissions();
    const navigate = useNavigate();

    const [isCreateOpen, setCreateOpen] = useState(false);
    const [newName, setNewName] = useState('');
    const [newDomain, setNewDomain] = useState('');
    const [isCreating, setIsCreating] = useState(false);
    const [newDefaultFlatrateLevel, setNewDefaultFlatrateLevel] = useState<'none' | 'web' | 'print' | 'original'>('none');
    const [newAutoJoinPolicy, setNewAutoJoinPolicy] = useState<'immediate' | 'requires_invite' | 'disabled'>('immediate');

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsCreating(true);
        try {
            await createTenant({
                name: newName,
                domain: newDomain || null,
                invoice_frequency: 'immediate',
                default_flatrate_level: newDefaultFlatrateLevel,
                auto_join_policy: newAutoJoinPolicy,
            });
            showToast('success', 'Organisation erstellt');
            setCreateOpen(false);
            setNewName('');
            setNewDomain('');
            setNewDefaultFlatrateLevel('none');
            setNewAutoJoinPolicy('immediate');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Fehler beim Erstellen');
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
                    <h1 className="text-4xl font-bold mb-2">Organisationen</h1>
                    <p className="opacity-70">Verwalte Organisationen, deren Mitarbeiter und Sammelrechnungen.</p>
                </div>
                {isAdmin && <button className="btn btn-primary" onClick={() => setCreateOpen(true)}>+ Neue Organisation</button>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {tenants?.map(t => (
                    <div key={t.id} className="card bg-base-100 border border-base-300 shadow-sm hover:shadow-md transition-shadow cursor-pointer" onClick={() => navigate(`/tenants/${t.id}`)}>
                        <div className="card-body p-5">
                            <h2 className="card-title text-xl text-primary">{t.name}</h2>
                            {t.domain ? <code className="text-sm bg-base-200 p-1 rounded">@{t.domain}</code> : <span className="text-sm opacity-50 italic">Keine Auto-Join Domain</span>}
                            
                                <div className="flex gap-4 mt-4 text-sm opacity-80">
                                    <div className="flex items-center gap-1"><span className="iconify mdi--account-group"></span> {t.users_count || 0} User</div>
                                    <div className="flex items-center gap-1"><span className="iconify mdi--folder-multiple"></span> {t.gallery_groups_count || 0} Meta-Galerien</div>
                                </div>
                                <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs opacity-60">
                                    <span>Flatrate: {t.default_flatrate_level || 'keine'}</span>
                                    <span>Rechnung: {t.invoice_frequency === 'immediate' ? 'Einzel' : t.invoice_frequency === 'monthly' ? 'Monatlich' : 'Quartal'}</span>
                                </div>
                        </div>
                    </div>
                ))}
                {tenants?.length === 0 && (
                    <EmptyState icon="mdi--domain" title="Noch keine Organisationen angelegt." className="col-span-full py-12" />
                )}
            </div>

            {isCreateOpen && (
                <div className="modal modal-open">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setCreateOpen(false)}>✕</button>
                        <h3 className="font-bold text-lg mb-4">Neue Organisation anlegen</h3>
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
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Standard-Flatrate-Level</span></label>
                                <select value={newDefaultFlatrateLevel} onChange={e => setNewDefaultFlatrateLevel(e.target.value as 'none' | 'web' | 'print' | 'original')} className="select select-bordered">
                                    <option value="none">Keine Flatrate</option>
                                    <option value="web">Web</option>
                                    <option value="print">Print</option>
                                    <option value="original">Original</option>
                                </select>
                            </div>
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Auto-Join Policy</span></label>
                                <select value={newAutoJoinPolicy} onChange={e => setNewAutoJoinPolicy(e.target.value as 'immediate' | 'requires_invite' | 'disabled')} className="select select-bordered">
                                    <option value="immediate">Sofort (automatisch)</option>
                                    <option value="requires_invite">Einladung erforderlich</option>
                                    <option value="disabled">Deaktiviert</option>
                                </select>
                            </div>
                            <div className="modal-action col-span-full">
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
