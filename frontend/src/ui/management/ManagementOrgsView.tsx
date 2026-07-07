import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { useOrgs } from '../../logic/useOrgs';
import { useUI } from '../components/UIContext';
import { useNavigate } from 'react-router-dom';
import { usePermissions } from '../../logic/usePermissions';
import PageLayout from '../components/PageLayout';
import EmptyState from '../components/EmptyState';

export default function ManagementOrgsView() {
    const { orgs, createOrg, isLoading } = useOrgs();
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
            await createOrg({
                name: newName,
                domain: newDomain || null,
                invoice_frequency: 'immediate',
                default_flatrate_level: newDefaultFlatrateLevel,
                auto_join_policy: newAutoJoinPolicy,
            });
            showToast('success', t`Organisation erstellt`);
            setCreateOpen(false);
            setNewName('');
            setNewDomain('');
            setNewDefaultFlatrateLevel('none');
            setNewAutoJoinPolicy('immediate');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Erstellen`);
        } finally {
            setIsCreating(false);
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;

    return (
        <PageLayout currentView="orgs">
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full relative">
            <div className="flex justify-between items-center mb-8">
                <div>
                    <h1 className="text-4xl font-bold mb-2"><Trans>Organisationen</Trans></h1>
                    <p className="opacity-70"><Trans>Verwalte Organisationen, deren Mitarbeiter und Sammelrechnungen.</Trans></p>
                </div>
                {isAdmin && <button className="btn btn-primary" onClick={() => setCreateOpen(true)}>+ <Trans>Neue Organisation</Trans></button>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {orgs?.map(t => (
                    <div key={t.id} className="card bg-base-100 border border-base-300 shadow-sm hover:shadow-md transition-shadow cursor-pointer" onClick={() => navigate(`/orgs/${t.id}`)}>
                        <div className="card-body p-5">
                            <h2 className="card-title text-xl text-primary">{t.name}</h2>
                            {t.domain ? <code className="text-sm bg-base-200 p-1 rounded">@{t.domain}</code> : <span className="text-sm opacity-50 italic"><Trans>Keine Auto-Join Domain</Trans></span>}
                            
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
                {orgs?.length === 0 && (
                    <EmptyState icon="mdi--domain" title="Noch keine Organisationen angelegt." className="col-span-full py-12" />
                )}
            </div>

            {isCreateOpen && (
                <div className="modal modal-open">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => setCreateOpen(false)}>✕</button>
                        <h3 className="font-bold text-lg mb-4"><Trans>Neue Organisation anlegen</Trans></h3>
                        <form onSubmit={handleCreate} className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold"><Trans>Name (z.B. Firma XYZ)</Trans></span></label>
                                <input type="text" required value={newName} onChange={e => setNewName(e.target.value)} className="input input-bordered" />
                            </div>
                            <div className="form-control">
                                <label className="label">
                                        <span className="label-text font-bold"><Trans>Auto-Join Domain</Trans></span>
                                        <span className="label-text-alt opacity-70"><Trans>ohne @</Trans></span>
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
                                <option value="immediate"><Trans>Sofort (automatisch)</Trans></option>
                                <option value="requires_invite"><Trans>Einladung erforderlich</Trans></option>
                                <option value="disabled"><Trans>Deaktiviert</Trans></option>
                                </select>
                            </div>
                            <div className="modal-action col-span-full">
                                <button type="button" className="btn btn-ghost" onClick={() => setCreateOpen(false)}><Trans>Abbrechen</Trans></button>
                                <button type="submit" className="btn btn-primary" disabled={isCreating}><Trans>Speichern</Trans></button>
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
