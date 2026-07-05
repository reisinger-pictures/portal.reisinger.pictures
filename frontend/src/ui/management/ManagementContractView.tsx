import {useState, useCallback} from 'react';
import {usePermissions} from '../../logic/usePermissions';
import {useUI} from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import InvoiceItemsTable from './components/invoice/InvoiceItemsTable';
import InvoiceDiscountsSection from './components/invoice/InvoiceDiscountsSection';
import InvoiceTotalSummary from './components/invoice/InvoiceTotalSummary';
import {
    useContracts,
    createContract,
    updateContract,
    openContract,
    closeContract,
    Contract,
    BillingDetails,
} from '../../logic/useContractManagement';
import type {InvoiceItem, InvoiceDiscount} from '../../api';

function toInvoiceItem(i: Contract['items'][number]): InvoiceItem {
    return {type: i.type, description: i.description, notes: i.notes, qty: i.qty, price: i.price, row_total: i.row_total};
}

function toInvoiceDiscount(i: Contract['discounts'][number]): InvoiceDiscount {
    return {type: i.type, description: i.description, notes: i.notes, price: i.price, row_total: i.row_total};
}

const emptyBilling: BillingDetails = {
    name: '', company: '', street: '', zip: '', city: '', country: '', email: '', uid: '',
};

export default function ManagementContractView() {
    const {isSuperAdmin} = usePermissions();
    const {contracts, isLoading: loadingContracts, mutate: mutateList} = useContracts();
    const {showToast, confirm} = useUI();

    const [editingContract, setEditingContract] = useState<Contract | null>(null);
    const [isNew, setIsNew] = useState(true);

    const [items, setItems] = useState<InvoiceItem[]>(() => [{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    const [discounts, setDiscounts] = useState<InvoiceDiscount[]>([]);
    const [termsHtml, setTermsHtml] = useState('');
    const [availableRoles, setAvailableRoles] = useState<string[]>([]);
    const [allowMultipleRoles, setAllowMultipleRoles] = useState(false);
    const [billingDetails, setBillingDetails] = useState<BillingDetails>({...emptyBilling});
    const [closesAt, setClosesAt] = useState('');
    const [roleInput, setRoleInput] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [joinLink, setJoinLink] = useState<string | null>(null);

    const resetForm = useCallback(() => {
        setItems([{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
        setDiscounts([]);
        setTermsHtml('');
        setAvailableRoles([]);
        setAllowMultipleRoles(false);
        setBillingDetails({...emptyBilling});
        setClosesAt('');
        setJoinLink(null);
    }, []);

    const loadContract = useCallback((contract: Contract) => {
        setItems(contract.items.length > 0 ? contract.items.map(toInvoiceItem) : [{type: 'item', description: '', notes: '', qty: 1, price: 0}]);
        setDiscounts(contract.discounts.map(toInvoiceDiscount));
        setTermsHtml(contract.terms_html || '');
        setAvailableRoles(contract.available_roles || []);
        setAllowMultipleRoles(contract.allow_multiple_roles_per_signer);
        setBillingDetails(contract.billing_details ?? {...emptyBilling});
        setClosesAt(contract.closes_at || '');
        setJoinLink(null);
    }, []);

    const handleSelectContract = (contract: Contract) => {
        setEditingContract(contract);
        setIsNew(false);
        loadContract(contract);
    };

    const handleNewContract = () => {
        setEditingContract(null);
        setIsNew(true);
        resetForm();
    };

    const handleSave = async () => {
        setIsSaving(true);
        try {
            const payload = {
                items: items.map(i => ({type: i.type as Contract['items'][number]['type'], description: i.description, notes: i.notes, qty: i.qty, price: i.price})),
                discounts: discounts.map(d => ({type: d.type as Contract['discounts'][number]['type'], description: d.description, notes: d.notes, qty: 1, price: d.price})),
                terms_html: termsHtml,
                available_roles: availableRoles,
                allow_multiple_roles_per_signer: allowMultipleRoles,
                billing_details: billingDetails,
                closes_at: closesAt,
            };

            if (isNew) {
                const created = await createContract(payload);
                setEditingContract(created);
                setIsNew(false);
                showToast('success', 'Vertrag wurde erstellt.');
            } else if (editingContract) {
                await updateContract(editingContract.id, payload);
                showToast('success', 'Vertrag wurde aktualisiert.');
            }
            await mutateList();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Fehler beim Speichern.');
        }
        setIsSaving(false);
    };

    const handleOpen = async () => {
        if (!editingContract) return;
        const ok = await confirm({
            title: 'Vertragsperiode starten',
            message: 'Nach dem Start kann der Vertrag nicht mehr bearbeitet werden. Der generierte Join-Link wird an die Unterzeichner weitergegeben. Fortfahren?',
            confirmText: 'Starten',
            confirmColor: 'primary',
        });
        if (!ok) return;
        setIsSaving(true);
        try {
            const result = await openContract(editingContract.id);
            setJoinLink(result.join_link);
            setEditingContract(result.contract);
            showToast('success', 'Vertragsperiode wurde gestartet.');
            await mutateList();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Fehler beim Öffnen.');
        }
        setIsSaving(false);
    };

    const handleClose = async () => {
        if (!editingContract) return;
        const ok = await confirm({
            title: 'Vertrag schließen',
            message: 'Nach dem Schließen können keine weiteren Unterzeichner beitreten oder unterschreiben. Fortfahren?',
            confirmText: 'Schließen',
            confirmColor: 'warning',
        });
        if (!ok) return;
        setIsSaving(true);
        try {
            const result = await closeContract(editingContract.id);
            setEditingContract(result.contract);
            showToast('success', 'Vertrag wurde geschlossen.');
            await mutateList();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : 'Fehler beim Schließen.');
        }
        setIsSaving(false);
    };

    const handleCopyLink = async () => {
        if (!joinLink) return;
        try {
            await navigator.clipboard.writeText(joinLink);
            showToast('success', 'Link wurde kopiert.');
        } catch {
            showToast('error', 'Konnte Link nicht kopieren.');
        }
    };

    const handleAddRole = () => {
        const trimmed = roleInput.trim();
        if (trimmed && !availableRoles.includes(trimmed)) {
            setAvailableRoles(prev => [...prev, trimmed]);
        }
        setRoleInput('');
    };

    const handleRemoveRole = (index: number) => {
        setAvailableRoles(prev => prev.filter((_, i) => i !== index));
    };

    const handleItemChange = (index: number, field: string, value: string | number) => {
        setItems(prev => {
            const next = [...prev];
            next[index] = {...next[index], [field]: value};
            return next;
        });
    };

    const addItem = () => {
        setItems(prev => [...prev, {type: 'item', description: '', notes: '', qty: 1, price: 0}]);
    };

    const removeItem = (index: number) => {
        setItems(prev => prev.filter((_, i) => i !== index));
    };

    const moveItemUp = (index: number) => {
        if (index === 0) return;
        setItems(prev => {
            const next = [...prev];
            const tmp = next[index - 1];
            next[index - 1] = next[index];
            next[index] = tmp;
            return next;
        });
    };

    const moveItemDown = (index: number) => {
        setItems(prev => {
            if (index === prev.length - 1) return prev;
            const next = [...prev];
            const tmp = next[index + 1];
            next[index + 1] = next[index];
            next[index] = tmp;
            return next;
        });
    };

    const handleDiscountChange = (index: number, field: string, value: string | number) => {
        setDiscounts(prev => {
            const next = [...prev];
            next[index] = {...next[index], [field]: value};
            return next;
        });
    };

    const addDiscount = () => {
        setDiscounts(prev => [...prev, {type: 'discount_fixed', description: '', notes: '', price: 0}]);
    };

    const removeDiscount = (index: number) => {
        setDiscounts(prev => prev.filter((_, i) => i !== index));
    };

    const handleBillingField = (field: keyof BillingDetails, value: string) => {
        setBillingDetails(prev => ({...prev, [field]: value}));
    };

    const subtotal = items.reduce((sum, i) => sum + (i.price * i.qty), 0);
    const total = discounts.reduce(
        (t, d) => (d.type === 'discount_percent' ? t * (1 - d.price / 100) : t - d.price),
        subtotal,
    );

    const statusBadge = (s: Contract['status']) => {
        const map: Record<string, string> = {
            draft: 'badge-ghost',
            active: 'badge-success',
            closed: 'badge-warning',
            cancelled: 'badge-error',
        };
        const labels: Record<string, string> = {
            draft: 'Entwurf',
            active: 'Aktiv',
            closed: 'Geschlossen',
            cancelled: 'Storniert',
        };
        return <span className={`badge ${map[s] || 'badge-ghost'} badge-sm`}>{labels[s] || s}</span>;
    };

    if (!isSuperAdmin) return <div className="p-8"><ErrorMessage message="Keine Berechtigung."/></div>;

    return (
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full space-y-6">
            {/* Contract list or form toggle */}
            <div className="flex items-center justify-between gap-4 flex-wrap">
                <h1 className="text-2xl font-bold flex items-center gap-2">
                    <span className="iconify mdi--file-sign text-primary"></span>
                    Vertragsmanagement
                </h1>
                <button onClick={handleNewContract} className="btn btn-primary btn-sm">
                    <span className="iconify mdi--plus"></span> Neuer Vertrag
                </button>
            </div>

            {/* Contract list */}
            {loadingContracts ? (
                <div className="flex justify-center py-12">
                    <span className="loading loading-spinner loading-lg text-primary"></span>
                </div>
            ) : (
                <div className="bg-base-100 p-4 rounded-box border border-base-300 shadow-sm overflow-x-auto">
                    {contracts.length === 0 ? (
                        <p className="text-sm opacity-50 italic p-4">Noch keine Verträge vorhanden.</p>
                    ) : (
                        <table className="table table-sm w-full">
                            <thead>
                            <tr>
                                <th>Status</th>
                                <th>Rollen</th>
                                <th>Unterzeichner</th>
                                <th>Erstellt</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            {contracts.map(c => (
                                <tr key={c.id}
                                    className={`cursor-pointer hover:bg-base-200 ${editingContract?.id === c.id ? 'bg-base-200' : ''}`}
                                    onClick={() => handleSelectContract(c)}>
                                    <td>{statusBadge(c.status)}</td>
                                    <td className="font-mono text-xs">{c.available_roles?.join(', ') || '—'}</td>
                                    <td>{c.signers?.length ?? 0}</td>
                                    <td className="text-xs opacity-60">{new Date(c.created_at).toLocaleDateString('de-DE')}</td>
                                    <td>
                                        <span className="iconify mdi--chevron-right opacity-40"></span>
                                    </td>
                                </tr>
                            ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}

            {/* Form */}
            {(isNew || editingContract !== null) && (
                <div className="space-y-6">
                    {/* Header */}
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <h2 className="font-bold text-xl">
                                {isNew ? 'Neuen Vertrag erstellen' : 'Vertrag bearbeiten'}
                            </h2>
                            {!isNew && (
                                <p className="text-sm opacity-60 mt-1">
                                    {statusBadge(editingContract?.status || 'draft')}
                                    {editingContract?.created_at && (
                                        <span className="ml-3">Erstellt: {new Date(editingContract.created_at).toLocaleDateString('de-DE')}</span>
                                    )}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Roles section */}
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4">Rollen (verfügbar für Unterzeichner)</h2>
                        <div className="flex gap-2 mb-3">
                            <input
                                type="text"
                                value={roleInput}
                                onChange={e => setRoleInput(e.target.value)}
                                onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); handleAddRole(); }}}
                                placeholder="z.B. Fotograf, Model, Agentur"
                                className="input input-sm input-bordered flex-1"
                            />
                            <button type="button" onClick={handleAddRole} className="btn btn-sm btn-outline btn-primary">
                                + Hinzufügen
                            </button>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {availableRoles.map((role, idx) => (
                                <span key={idx} className="badge badge-primary gap-1 py-3 px-3">
                                    {role}
                                    <button type="button" onClick={() => handleRemoveRole(idx)}
                                            className="ml-1 hover:text-error transition-colors">
                                        <span className="iconify mdi--close text-sm"></span>
                                    </button>
                                </span>
                            ))}
                            {availableRoles.length === 0 && (
                                <p className="text-sm opacity-50 italic">Keine Rollen definiert. Unterzeichner können ohne Rollenzuweisung beitreten.</p>
                            )}
                        </div>
                        <div className="form-control mt-4">
                            <label className="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" checked={allowMultipleRoles}
                                       onChange={e => setAllowMultipleRoles(e.target.checked)}
                                       className="checkbox checkbox-primary checkbox-sm"/>
                                <span className="label-text">Mehrere Rollen pro Unterzeichner erlauben</span>
                            </label>
                        </div>
                    </div>

                    {/* Billing section */}
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <details className="group">
                            <summary className="cursor-pointer font-bold text-xl list-none flex items-center gap-2">
                                <span className="iconify mdi--chevron-right group-open:rotate-90 transition-transform"></span>
                                Rechnungsempfänger (optional)
                            </summary>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Name</span></label>
                                    <input type="text" value={billingDetails.name || ''}
                                           onChange={e => handleBillingField('name', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Firma</span></label>
                                    <input type="text" value={billingDetails.company || ''}
                                           onChange={e => handleBillingField('company', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Straße</span></label>
                                    <input type="text" value={billingDetails.street || ''}
                                           onChange={e => handleBillingField('street', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">PLZ</span></label>
                                    <input type="text" value={billingDetails.zip || ''}
                                           onChange={e => handleBillingField('zip', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Stadt</span></label>
                                    <input type="text" value={billingDetails.city || ''}
                                           onChange={e => handleBillingField('city', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">Land</span></label>
                                    <input type="text" value={billingDetails.country || ''}
                                           onChange={e => handleBillingField('country', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">E-Mail</span></label>
                                    <input type="email" value={billingDetails.email || ''}
                                           onChange={e => handleBillingField('email', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                                <div className="form-control">
                                    <label className="label py-1"><span className="label-text text-sm font-bold">UID</span></label>
                                    <input type="text" value={billingDetails.uid || ''}
                                           onChange={e => handleBillingField('uid', e.target.value)}
                                           className="input input-sm input-bordered"/>
                                </div>
                            </div>
                        </details>
                    </div>

                    {/* Closes at */}
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4">Vertragsperiode</h2>
                        <div className="form-control max-w-xs">
                            <label className="label py-1"><span className="label-text text-sm font-bold">Gültig bis</span></label>
                            <input type="date" value={closesAt}
                                   onChange={e => setClosesAt(e.target.value)}
                                   className="input input-sm input-bordered"/>
                        </div>
                    </div>

                    {/* Items / Discounts (reuse existing invoice components) */}
                    <InvoiceItemsTable
                        items={items}
                        onItemChange={handleItemChange}
                        onAddItem={addItem}
                        onRemoveItem={removeItem}
                        onMoveItemUp={moveItemUp}
                        onMoveItemDown={moveItemDown}
                    />

                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <InvoiceDiscountsSection
                            discounts={discounts}
                            onDiscountChange={handleDiscountChange}
                            onAddDiscount={addDiscount}
                            onRemoveDiscount={removeDiscount}
                        />
                        <InvoiceTotalSummary total={total}/>
                    </div>

                    {/* Terms */}
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4">Allgemeine Vertragsbedingungen</h2>
                        <WysiwygEditor value={termsHtml} onChange={setTermsHtml}/>
                    </div>

                    {/* Join Link display */}
                    {joinLink && (
                        <div className="bg-base-100 p-6 rounded-box border border-success/30 shadow-sm">
                            <h2 className="font-bold text-xl mb-2 flex items-center gap-2 text-success">
                                <span className="iconify mdi--link-variant"></span>
                                Join-Link
                            </h2>
                            <p className="text-sm opacity-70 mb-3">Teile diesen Link mit den Unterzeichnern:</p>
                            <div className="join w-full">
                                <input type="text" readOnly value={joinLink}
                                       className="input input-bordered join-item w-full font-mono text-sm"/>
                                <button onClick={handleCopyLink} className="btn btn-primary join-item">
                                    <span className="iconify mdi--clipboard-text"></span> Kopieren
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Signers list */}
                    {editingContract?.signers && editingContract.signers.length > 0 && (
                        <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                            <h2 className="font-bold text-xl mb-4">Unterzeichner</h2>
                            <div className="overflow-x-auto">
                                <table className="table table-sm w-full">
                                    <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>E-Mail</th>
                                        <th>Rollen</th>
                                        <th>Status</th>
                                        <th>Unterschrieben am</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    {editingContract.signers.map(s => (
                                        <tr key={s.id}>
                                            <td className="font-medium">{s.name}</td>
                                            <td className="text-sm opacity-70">{s.email}</td>
                                            <td className="text-xs">{s.roles.join(', ') || '—'}</td>
                                            <td>{s.status === 'signed' ? <span className="badge badge-success badge-sm">Unterschrieben</span> : s.status === 'joined' ? <span className="badge badge-info badge-sm">Beigetreten</span> : <span className="badge badge-ghost badge-sm">Eingeladen</span>}</td>
                                            <td className="text-xs opacity-60">{s.signed_at ? new Date(s.signed_at).toLocaleDateString('de-DE') : '—'}</td>
                                        </tr>
                                    ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap gap-3 justify-end pt-4 pb-20">
                        {(editingContract?.status ?? 'draft') === 'draft' && (
                            <>
                                <button onClick={handleSave} disabled={isSaving}
                                        className="btn btn-primary shadow-xl">
                                    {isSaving ? <span className="loading loading-spinner"></span> : <span className="iconify mdi--content-save"></span>}
                                    {isNew ? 'Vertrag erstellen' : 'Entwurf speichern'}
                                </button>
                                {!isNew && (
                                    <button onClick={handleOpen} disabled={isSaving}
                                            className="btn btn-success shadow-xl">
                                        <span className="iconify mdi--play-circle"></span>
                                        Vertragsperiode starten
                                    </button>
                                )}
                            </>
                        )}
                        {editingContract?.status === 'active' && (
                            <>
                                <button onClick={handleClose} disabled={isSaving}
                                        className="btn btn-warning shadow-xl">
                                    <span className="iconify mdi--stop-circle"></span>
                                    Vertrag schließen
                                </button>
                            </>
                        )}
                        {editingContract?.status === 'closed' && (
                            <p className="text-sm opacity-50 italic">Dieser Vertrag ist geschlossen und kann nicht mehr bearbeitet werden.</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
