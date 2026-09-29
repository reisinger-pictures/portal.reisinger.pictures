import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useState} from 'react';
import {useNavigate, useParams} from 'react-router-dom';
import {Org, OrgUser, useOrgs} from '../../logic/useOrgs';
import {useUsers} from '../../logic/useUsers';
import {FlatGroup} from '../../logic/useGalleries';
import {flattenGroups} from '../../logic/utils';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {apiMutate} from '../../api';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import PageLayout from '../components/PageLayout';
import ModalDialogShell from '../components/ModalDialogShell';

interface OrgSettingsProps {
    name: string;
    setName: (v: string) => void;
    domain: string;
    setDomain: (v: string) => void;
    freq: 'immediate' | 'monthly' | 'quarterly';
    setFreq: (v: 'immediate' | 'monthly' | 'quarterly') => void;
    defaultFlatrateLevel: 'none' | 'web' | 'print' | 'original';
    setDefaultFlatrateLevel: (v: 'none' | 'web' | 'print' | 'original') => void;
    sharedFlatrateCents: number;
    setSharedFlatrateCents: (v: number) => void;
    autoJoinPolicy: 'immediate' | 'requires_invite' | 'disabled';
    setAutoJoinPolicy: (v: 'immediate' | 'requires_invite' | 'disabled') => void;
    handleSaveGeneral: (e: React.FormEvent) => void;
}

const OrgSettings = ({name, setName, domain, setDomain, freq, setFreq, defaultFlatrateLevel, setDefaultFlatrateLevel, sharedFlatrateCents, setSharedFlatrateCents, autoJoinPolicy, setAutoJoinPolicy, handleSaveGeneral}: OrgSettingsProps) => (
    <form onSubmit={handleSaveGeneral}
          className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm space-y-4">
        <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4"><Trans>Einstellungen</Trans></h2>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold"><Trans>Organisations-Name</Trans></span></label>
            <input type="text" value={name} onChange={e => setName(e.target.value)} required
                   className="input input-bordered"/>
        </div>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold"><Trans>Auto-Join Domain</Trans></span></label>
            <input type="text" value={domain} onChange={e => setDomain(e.target.value)} placeholder="firma.de"
                   className="input input-bordered font-mono"/>
        </div>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold"><Trans>Rechnungs-Rhythmus</Trans></span></label>
            <select value={freq} onChange={e => setFreq(e.target.value as 'immediate' | 'monthly' | 'quarterly')}
                    className="select select-bordered">
                <option value="immediate"><Trans>Sofort (Einzelrechnung)</Trans></option>
                <option value="monthly"><Trans>Monatlich (Sammelrechnung)</Trans></option>
                <option value="quarterly"><Trans>Quartal (Sammelrechnung)</Trans></option>
            </select>
        </div>
        <div className="form-control">
            <label className="label"><span className="label-text font-bold"><Trans>Standard-Flatrate-Level</Trans></span></label>
            <select value={defaultFlatrateLevel} onChange={e => setDefaultFlatrateLevel(e.target.value as 'none' | 'web' | 'print' | 'original')}
                    className="select select-bordered">
                <option value="none"><Trans>Keine Flatrate</Trans></option>
                <option value="web">Web</option>
                <option value="print">Print</option>
                <option value="original">Original</option>
            </select>
        </div>
        {defaultFlatrateLevel !== 'none' && (
            <div className="form-control">
                <label className="label"><span className="label-text font-bold"><Trans>Geteiltes Flatrate-Budget (Cent)</Trans></span></label>
                <input type="number" min="0" value={sharedFlatrateCents || ''}
                       onChange={e => setSharedFlatrateCents(Number(e.target.value))}
                       className="input input-bordered" placeholder={t`z.B. 50000 für 500€`}/>
            </div>
        )}
        <div className="form-control">
            <label className="label"><span className="label-text font-bold"><Trans>Auto-Join Policy</Trans></span></label>
            <select value={autoJoinPolicy} onChange={e => setAutoJoinPolicy(e.target.value as 'immediate' | 'requires_invite' | 'disabled')}
                    className="select select-bordered">
                <option value="immediate"><Trans>Sofort (automatisch)</Trans></option>
                <option value="requires_invite"><Trans>Einladung erforderlich</Trans></option>
                <option value="disabled"><Trans>Deaktiviert</Trans></option>
            </select>
        </div>
        <button type="submit" className="btn btn-primary w-full mt-4"><Trans>Speichern</Trans></button>
    </form>
);

interface OrgInvoicingProps {
    org: Org;
    isGenerating: boolean;
    handleGenerateInvoice: () => void;
}

const OrgInvoicing = ({org, isGenerating, handleGenerateInvoice}: OrgInvoicingProps) => (
    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm space-y-4 mt-6">
        <h2 className="font-bold text-xl border-b border-base-300 pb-2 mb-4 flex items-center gap-2">
            <span className="iconify mdi--receipt-text text-primary"></span> <Trans>Abrechnung</Trans>
        </h2>
        <div className="flex justify-between items-center">
            <div>
                <div className="font-bold"><Trans>Offene Lieferscheine</Trans></div>
                <div className="text-sm opacity-70"><Trans>Auszustellende Sammelrechnung</Trans></div>
            </div>
            <div className="text-3xl font-mono font-bold text-warning">{org.open_delivery_notes_count || 0}</div>
        </div>
        <button
            onClick={handleGenerateInvoice}
            disabled={!org.open_delivery_notes_count || org.open_delivery_notes_count === 0 || isGenerating}
            className="btn btn-primary w-full mt-4"
        >
            {isGenerating ? <span className="loading loading-spinner"></span> : <Trans>Sammelrechnung erstellen</Trans>}
        </button>
    </div>
);

interface OrgRelationsProps {
    users?: OrgUser[];
    flatGroups: FlatGroup[];
    selUsers: string[];
    setSelUsers: React.Dispatch<React.SetStateAction<string[]>>;
    selGroups: string[];
    setSelGroups: React.Dispatch<React.SetStateAction<string[]>>;
    handleSaveRelations: () => void;
    setInviteModalOpen: (v: boolean) => void;
    toggleId: (arr: string[], setArr: React.Dispatch<React.SetStateAction<string[]>>, toggleId: string) => void;
}

const OrgRelations = ({
                         users,
                         flatGroups,
                         selUsers,
                         setSelUsers,
                         selGroups,
                         setSelGroups,
                         handleSaveRelations,
                         setInviteModalOpen,
                         toggleId
                     }: OrgRelationsProps) => (
    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm h-full flex flex-col">
        <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
            <h2 className="font-bold text-xl"><Trans>Zuweisungen</Trans></h2>
            <button onClick={handleSaveRelations} className="btn btn-primary btn-sm"><Trans>Zuweisungen speichern</Trans></button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 flex-1 min-h-0">
            <div className="flex flex-col h-full border border-base-300 rounded overflow-hidden">
                <div className="bg-base-200 p-2 flex justify-between items-center shrink-0 border-b border-base-300">
                    <span className="font-bold text-sm"><Trans>Zugeordnete Nutzer</Trans></span>
                    <button className="btn btn-xs btn-primary" onClick={() => setInviteModalOpen(true)}>+ <Trans>Einladen</Trans>
                    </button>
                </div>
                <div className="p-2 overflow-y-auto flex-1 h-64">
                    {users?.map(u => (
                        <label key={u.id}
                               className="label cursor-pointer justify-start gap-3 p-2 rounded hover:bg-base-200 transition-colors">
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
                <div className="bg-base-200 p-2 font-bold text-sm shrink-0 border-b border-base-300"><Trans>Zugewiesene
                    Meta-Galerien</Trans>
                </div>
                <div className="p-2 overflow-y-auto flex-1 h-64">
                    {flatGroups.map(g => (
                        <label key={g.id}
                               className="label cursor-pointer justify-start gap-3 p-2 rounded hover:bg-base-200 transition-colors">
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

export default function ManagementOrgDetailView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {showToast, confirm} = useUI();
    const {
        org,
        isLoading,
        updateOrg,
        deleteOrg,
        syncUsers,
        syncGroups,
        generateCollectiveInvoice
    } = useOrgs(id);
    const {users} = useUsers();
    const {tree} = useProtectedGalleries();

    const [name, setName] = useState('');
    const [domain, setDomain] = useState('');
    const [freq, setFreq] = useState<'immediate' | 'monthly' | 'quarterly'>('immediate');
    const [defaultFlatrateLevel, setDefaultFlatrateLevel] = useState<'none' | 'web' | 'print' | 'original'>('none');
    const [sharedFlatrateCents, setSharedFlatrateCents] = useState(0);
    const [autoJoinPolicy, setAutoJoinPolicy] = useState<'immediate' | 'requires_invite' | 'disabled'>('immediate');

    const [selUsers, setSelUsers] = useState<string[]>([]);
    const [selGroups, setSelGroups] = useState<string[]>([]);
    const [isInviteModalOpen, setInviteModalOpen] = useState(false);
    const [inviteEmail, setInviteEmail] = useState('');
    const [isInviting, setIsInviting] = useState(false);

    const [prevOrgId, setPrevOrgId] = useState<string | null>(null);
    if (org && org.id !== prevOrgId) {
        setPrevOrgId(org.id);
        setName(org.name);
        setDomain(org.domain || '');
        setFreq(org.invoice_frequency);
        setDefaultFlatrateLevel(org.default_flatrate_level || 'none');
        setSharedFlatrateCents(org.shared_flatrate_cents ?? 0);
        setAutoJoinPolicy(org.auto_join_policy || 'immediate');
        setSelUsers(org.users?.map(u => u.id) || []);
        setSelGroups(org.gallery_groups?.map(g => g.id) || []);
    }

    const handleSaveGeneral = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            await updateOrg(id!, {
                name,
                domain: domain || null,
                invoice_frequency: freq,
                default_flatrate_level: defaultFlatrateLevel,
                shared_flatrate_cents: sharedFlatrateCents,
                auto_join_policy: autoJoinPolicy,
            });
            showToast('success', t`Organisation aktualisiert.`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        }
    };

    const handleSaveRelations = async () => {
        try {
            await syncUsers(id!, selUsers);
            await syncGroups(id!, selGroups);
            showToast('success', t`Zuweisungen gespeichert.`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err));
        }
    };

    const handleInvite = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsInviting(true);
        try {
            await apiMutate(`/api/management/orgs/${id}/invites`, 'POST', {email: inviteEmail});
            showToast('success', t`Einladung erfolgreich versendet.`);
            setInviteModalOpen(false);
            setInviteEmail('');
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : String(err) || t`Fehler beim Versenden der Einladung.`);
        } finally {
            setIsInviting(false);
        }
    };

    const handleDelete = async () => {
        if (await confirm({
            title: t`Organisation löschen?`,
            message: t`Wirklich löschen? Zuweisungen gehen verloren (Nutzer und Ordner bleiben aber erhalten).`,
            confirmColor: 'error'
        })) {
            await deleteOrg(id!);
            navigate('/orgs');
        }
    };

    const [isGenerating, setIsGenerating] = useState(false);

    const handleGenerateInvoice = async () => {
        if (await confirm({
            title: t`Sammelrechnung erstellen?`,
            message: t`Ausstehende Lieferscheine werden nun gebündelt, eine P-Rechnungsnummer erzeugt und als PDF an deine E-Mail versendet.`,
            confirmText: t`Erstellen`,
            confirmColor: 'primary'
        })) {
            setIsGenerating(true);
            try {
                const res = await generateCollectiveInvoice(id!);
                const invoiceNumber = res.invoice_number;
                const processedOrders = res.processed_orders;
                showToast('success', t`Sammelrechnung ${invoiceNumber} mit ${processedOrders} Positionen erfolgreich erstellt.`);
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
    if (!org) return <div className="p-10"><ErrorMessage message={t`Organisation nicht gefunden.`}/></div>;

    const flatGroups = tree ? flattenGroups(tree.groups) : [];

    return (
        <PageLayout currentView="orgs">
            <div className="p-6 md:p-10 max-w-6xl mx-auto w-full relative">
                <div className="flex items-center gap-4 mb-8">
                    <button onClick={() => navigate('/orgs')} className="btn btn-circle btn-ghost shrink-0"><span
                        className="iconify mdi--arrow-left text-2xl"></span></button>
                    <div>
                        <h1 className="text-3xl font-bold">{org.name}</h1>
                        <p className="opacity-70"><Trans>Organisations-Verwaltung</Trans></p>
                    </div>
                    <button onClick={handleDelete} className="btn btn-outline btn-error btn-sm ml-auto"><Trans>Löschen</Trans></button>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div className="lg:col-span-1 space-y-6">
                                    <OrgSettings name={name} setName={setName} domain={domain} setDomain={setDomain} freq={freq}
                                    setFreq={setFreq}
                                    defaultFlatrateLevel={defaultFlatrateLevel} setDefaultFlatrateLevel={setDefaultFlatrateLevel}
                                    sharedFlatrateCents={sharedFlatrateCents} setSharedFlatrateCents={setSharedFlatrateCents}
                                    autoJoinPolicy={autoJoinPolicy} setAutoJoinPolicy={setAutoJoinPolicy}
                                    handleSaveGeneral={handleSaveGeneral}/>
                        <OrgInvoicing org={org} isGenerating={isGenerating}
                                         handleGenerateInvoice={handleGenerateInvoice}/>
                    </div>
                    <div className="lg:col-span-2">
                        <OrgRelations users={users} flatGroups={flatGroups} selUsers={selUsers}
                                         setSelUsers={setSelUsers} selGroups={selGroups} setSelGroups={setSelGroups}
                                         handleSaveRelations={handleSaveRelations}
                                         setInviteModalOpen={setInviteModalOpen} toggleId={toggleId}/>
                    </div>
                </div>
            </div>

            {isInviteModalOpen && (
                <ModalDialogShell
                    title={<Trans>Nutzer in Organisation einladen</Trans>}
                    onClose={() => setInviteModalOpen(false)}
                    // Inviting only — no delete action for an existing member,
                    // so there is no `onDelete` and the shell renders no delete
                    // button.
                    isSubmitting={isInviting}
                    onSubmit={handleInvite}
                    // No `noValidate` on purpose: the e-mail field is `required`
                    // and this form was written to keep native constraint
                    // validation, so the shell must leave it on.
                    submitText={t`Einladung Senden`}
                >
                        <p className="text-sm opacity-70 mb-4"><Trans>Der Nutzer erhält eine E-Mail mit einem Link, um sein
                            Passwort festzulegen und wird automatisch dieser Organisation zugewiesen.</Trans></p>
                        <div className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span
                                    className="label-text font-bold"><Trans>E-Mail Adresse</Trans></span></label>
                                <input type="email" required value={inviteEmail}
                                       onChange={e => setInviteEmail(e.target.value)}
                                        className="input input-bordered w-full" placeholder={t`kollege@firma.de`}/>
                            </div>
                        </div>
                </ModalDialogShell>
            )}
        </PageLayout>
    );
}
