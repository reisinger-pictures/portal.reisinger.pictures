import {useState} from 'react';
import {useNavigate, useParams} from 'react-router-dom';
import {Tenant, TenantUser, useTenants} from '../../logic/useTenants';
import {useUsers} from '../../logic/useUsers';
import {FlatGroup} from '../../logic/useGalleries';
import {flattenGroups} from '../../logic/utils';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {apiMutate} from '../../api';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import PageLayout from '../components/PageLayout';

interface TenantSettingsProps {
    name: string;
    setName: (v: string) => void;
    domain: string;
    setDomain: (v: string) => void;
    freq: 'immediate' | 'monthly' | 'quarterly';
    setFreq: (v: 'immediate' | 'monthly' | 'quarterly') => void;
    handleSaveGeneral: (e: React.FormEvent) => void;
}

const TenantSettings = ({name, setName, domain, setDomain, freq, setFreq, handleSaveGeneral}: TenantSettingsProps) => (
    <form onSubmit={handleSaveGeneral}
          className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm space-y-4">
        <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4">Einstellungen</h2>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold">Organisations-Name</span></label>
            <input type="text" value={name} onChange={e => setName(e.target.value)} required
                   className="input input-bordered"/>
        </div>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold">Auto-Join Domain</span></label>
            <input type="text" value={domain} onChange={e => setDomain(e.target.value)} placeholder="firma.de"
                   className="input input-bordered font-mono"/>
        </div>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold">Rechnungs-Rhythmus</span></label>
            <select value={freq} onChange={e => setFreq(e.target.value as 'immediate' | 'monthly' | 'quarterly')}
                    className="select select-bordered">
                <option value="immediate">Sofort (Einzelrechnung)</option>
                <option value="monthly">Monatlich (Sammelrechnung)</option>
                <option value="quarterly">Quartal (Sammelrechnung)</option>
            </select>
        </div>
        <button type="submit" className="btn btn-primary w-full mt-4">Speichern</button>
    </form>
);

interface TenantInvoicingProps {
    tenant: Tenant;
    isGenerating: boolean;
    handleGenerateInvoice: () => void;
}

const TenantInvoicing = ({tenant, isGenerating, handleGenerateInvoice}: TenantInvoicingProps) => (
    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm space-y-4 mt-6">
        <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4 flex items-center gap-2">
            <span className="iconify mdi--receipt-text text-primary"></span> Abrechnung
        </h2>
        <div className="flex justify-between items-center">
            <div>
                <div className="font-bold">Offene Lieferscheine</div>
                <div className="text-sm opacity-70">Auszustellende Sammelrechnung</div>
            </div>
            <div className="text-3xl font-mono font-bold text-warning">{tenant.open_delivery_notes_count || 0}</div>
        </div>
        <button
            onClick={handleGenerateInvoice}
            disabled={!tenant.open_delivery_notes_count || tenant.open_delivery_notes_count === 0 || isGenerating}
            className="btn btn-primary w-full mt-4"
        >
            {isGenerating ? <span className="loading loading-spinner"></span> : 'Sammelrechnung erstellen'}
        </button>
    </div>
);

interface TenantRelationsProps {
    users?: TenantUser[];
    flatGroups: FlatGroup[];
    selUsers: string[];
    setSelUsers: React.Dispatch<React.SetStateAction<string[]>>;
    selGroups: string[];
    setSelGroups: React.Dispatch<React.SetStateAction<string[]>>;
    handleSaveRelations: () => void;
    setInviteModalOpen: (v: boolean) => void;
    toggleId: (arr: string[], setArr: React.Dispatch<React.SetStateAction<string[]>>, toggleId: string) => void;
}

const TenantRelations = ({
                             users,
                             flatGroups,
                             selUsers,
                             setSelUsers,
                             selGroups,
                             setSelGroups,
                             handleSaveRelations,
                             setInviteModalOpen,
                             toggleId
                         }: TenantRelationsProps) => (
    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm h-full flex flex-col">
        <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
            <h2 className="font-bold text-xl">Zuweisungen</h2>
            <button onClick={handleSaveRelations} className="btn btn-primary btn-sm">Zuweisungen speichern</button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 flex-1 min-h-0">
            <div className="flex flex-col h-full border border-base-300 rounded overflow-hidden">
                <div className="bg-base-200 p-2 flex justify-between items-center shrink-0 border-b border-base-300">
                    <span className="font-bold text-sm">Zugeordnete Nutzer</span>
                    <button className="btn btn-xs btn-primary" onClick={() => setInviteModalOpen(true)}>+ Einladen
                    </button>
                </div>
                <div className="p-2 overflow-y-auto flex-1 h-64">
                    {users?.map(u => (
                        <label key={u.id}
                               className="label cursor-pointer justify-start gap-3 p-1 hover:bg-base-200 rounded">
                            <input type="checkbox" checked={selUsers.includes(u.id)}
                                   onChange={() => toggleId(selUsers, setSelUsers, u.id)}
                                   className="checkbox checkbox-primary shrink-0"/>
                            <div className="min-w-0">
                                <span className="label-text block font-bold truncate">{u.name}</span>
                                <span className="text-sm opacity-70 block truncate">{u.email}</span>
                            </div>
                        </label>
                    ))}
                </div>
            </div>

            <div className="flex flex-col h-full border border-base-300 rounded overflow-hidden">
                <div className="bg-base-200 p-2 font-bold text-sm shrink-0 border-b border-base-300">Zugewiesene
                    Meta-Galerien (Ordner)
                </div>
                <div className="p-2 overflow-y-auto flex-1 h-64">
                    {flatGroups.map(g => (
                        <label key={g.id}
                               className="label cursor-pointer justify-start gap-3 p-1 hover:bg-base-200 rounded">
                            <input type="checkbox" checked={selGroups.includes(g.id)}
                                   onChange={() => toggleId(selGroups, setSelGroups, g.id)}
                                   className="checkbox checkbox-primary shrink-0"/>
                            <span className="label-text truncate">{'- '.repeat(g.depth)}{g.name}</span>
                        </label>
                    ))}
                </div>
            </div>
        </div>
    </div>
);

export default function ManagementTenantDetailView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {showToast, confirm} = useUI();
    const {
        tenant,
        isLoading,
        updateTenant,
        deleteTenant,
        syncUsers,
        syncGroups,
        generateCollectiveInvoice
    } = useTenants(id);
    const {users} = useUsers();
    const {tree} = useProtectedGalleries();

    const [name, setName] = useState('');
    const [domain, setDomain] = useState('');
    const [freq, setFreq] = useState<'immediate' | 'monthly' | 'quarterly'>('immediate');

    const [selUsers, setSelUsers] = useState<string[]>([]);
    const [selGroups, setSelGroups] = useState<string[]>([]);
    const [isInviteModalOpen, setInviteModalOpen] = useState(false);
    const [inviteEmail, setInviteEmail] = useState('');
    const [isInviting, setIsInviting] = useState(false);

    const [prevTenantId, setPrevTenantId] = useState<string | null>(null);
    if (tenant && tenant.id !== prevTenantId) {
        setPrevTenantId(tenant.id);
        setName(tenant.name);
        setDomain(tenant.domain || '');
        setFreq(tenant.invoice_frequency);
        setSelUsers(tenant.users?.map(u => u.id) || []);
        setSelGroups(tenant.gallery_groups?.map(g => g.id) || []);
    }

    const handleSaveGeneral = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            await updateTenant(id!, {name, domain: domain || null, invoice_frequency: freq});
            showToast('success', 'Organisation aktualisiert.');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        }
    };

    const handleSaveRelations = async () => {
        try {
            await syncUsers(id!, selUsers);
            await syncGroups(id!, selGroups);
            showToast('success', 'Zuweisungen gespeichert.');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        }
    };

    const handleInvite = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsInviting(true);
        try {
            await apiMutate(`/api/management/tenants/${id}/invites`, 'POST', {email: inviteEmail});
            showToast('success', 'Einladung erfolgreich versendet.');
            setInviteModalOpen(false);
            setInviteEmail('');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err) || 'Fehler beim Versenden der Einladung.');
        } finally {
            setIsInviting(false);
        }
    };

    const handleDelete = async () => {
        if (await confirm({
            title: 'Organisation löschen?',
            message: 'Wirklich löschen? Zuweisungen gehen verloren (Nutzer und Ordner bleiben aber erhalten).',
            confirmColor: 'error'
        })) {
            await deleteTenant(id!);
            navigate('/tenants');
        }
    };

    const [isGenerating, setIsGenerating] = useState(false);

    const handleGenerateInvoice = async () => {
        if (await confirm({
            title: 'Sammelrechnung erstellen?',
            message: 'Ausstehende Lieferscheine werden nun gebündelt, eine P-Rechnungsnummer erzeugt und als PDF an deine E-Mail versendet.',
            confirmText: 'Erstellen',
            confirmColor: 'primary'
        })) {
            setIsGenerating(true);
            try {
                const res = await generateCollectiveInvoice(id!);
                showToast('success', `Sammelrechnung ${res.invoice_number} mit ${res.processed_orders} Positionen erfolgreich erstellt.`);
            } catch (err: unknown) {
                showToast('error', err instanceof Error ? err.message : String(err) || 'Fehler bei der Erstellung.');
            } finally {
                setIsGenerating(false);
            }
        }
    };

    const toggleId = (arr: string[], setArr: React.Dispatch<React.SetStateAction<string[]>>, toggleId: string) => {
        setArr(arr.includes(toggleId) ? arr.filter(x => x !== toggleId) : [...arr, toggleId]);
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span
        className="loading loading-spinner loading-lg"></span></div>;
    if (!tenant) return <div className="p-10"><ErrorMessage message="Organisation nicht gefunden."/></div>;

    const flatGroups = tree ? flattenGroups(tree.groups) : [];

    return (
        <PageLayout currentView="tenants">
            <div className="p-6 md:p-10 max-w-6xl mx-auto w-full relative">
                <div className="flex items-center gap-4 mb-8">
                    <button onClick={() => navigate('/tenants')} className="btn btn-circle btn-ghost shrink-0"><span
                        className="iconify mdi--arrow-left text-2xl"></span></button>
                    <div>
                        <h1 className="text-3xl font-bold">{tenant.name}</h1>
                        <p className="opacity-70">Organisations-Verwaltung</p>
                    </div>
                    <button onClick={handleDelete} className="btn btn-outline btn-error btn-sm ml-auto">Löschen</button>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div className="lg:col-span-1 space-y-6">
                        <TenantSettings name={name} setName={setName} domain={domain} setDomain={setDomain} freq={freq}
                                        setFreq={setFreq} handleSaveGeneral={handleSaveGeneral}/>
                        <TenantInvoicing tenant={tenant} isGenerating={isGenerating}
                                         handleGenerateInvoice={handleGenerateInvoice}/>
                    </div>
                    <div className="lg:col-span-2">
                        <TenantRelations users={users} flatGroups={flatGroups} selUsers={selUsers}
                                         setSelUsers={setSelUsers} selGroups={selGroups} setSelGroups={setSelGroups}
                                         handleSaveRelations={handleSaveRelations}
                                         setInviteModalOpen={setInviteModalOpen} toggleId={toggleId}/>
                    </div>
                </div>
            </div>

            {isInviteModalOpen && (
                <div className="modal modal-open z-[60]">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                                onClick={() => setInviteModalOpen(false)}>✕
                        </button>
                        <h3 className="font-bold text-lg mb-4">Nutzer in Organisation einladen</h3>
                        <p className="text-sm opacity-70 mb-4">Der Nutzer erhält eine E-Mail mit einem Link, um sein
                            Passwort festzulegen und wird automatisch dieser Organisation zugewiesen.</p>
                        <form onSubmit={handleInvite} className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span
                                    className="label-text font-bold">E-Mail Adresse</span></label>
                                <input type="email" required value={inviteEmail}
                                       onChange={e => setInviteEmail(e.target.value)}
                                       className="input input-bordered w-full" placeholder="kollege@firma.de"/>
                            </div>
                            <div className="modal-action col-span-full">
                                <button type="button" className="btn btn-ghost"
                                        onClick={() => setInviteModalOpen(false)}>Abbrechen
                                </button>
                                <button type="submit" className="btn btn-primary" disabled={isInviting}>
                                    {isInviting ?
                                        <span className="loading loading-spinner"></span> : 'Einladung Senden'}
                                </button>
                            </div>
                        </form>
                    </div>
                    <div className="modal-backdrop" onClick={() => setInviteModalOpen(false)}></div>
                </div>
            )}
        </PageLayout>
    );
}
