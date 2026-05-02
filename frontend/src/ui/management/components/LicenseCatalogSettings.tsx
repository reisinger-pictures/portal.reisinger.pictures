import { useState } from 'react';
import { useLicenseCatalog, LicenseUseCase, LicenseModifier } from '../../../logic/useLicenseCatalog';
import { useUI } from '../../components/UIContext';
import { formatMoney } from '../../../logic/utils';

function UseCaseRow({ uc, onSave, onDelete }: { uc: LicenseUseCase, onSave: (id: string, data: Partial<LicenseUseCase>) => Promise<void>, onDelete: (id: string) => void }) {
    const [isEditing, setIsEditing] = useState(false);
    const [data, setData] = useState<Partial<LicenseUseCase>>({ ...uc, base_price: uc.base_price / 100, is_commercial: uc.is_commercial || false });
    const { showToast } = useUI();

    const handleSave = async () => {
        try {
            await onSave(uc.id, { ...data, base_price: Math.round(Number(data.base_price) * 100) });
            showToast('success', 'Kategorie aktualisiert');
            setIsEditing(false);
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    if (isEditing) {
        return (
            <tr>
                <td>
                    <div className="flex flex-col gap-2">
                        <input type="text" className="input input-bordered w-full" value={data.name || ''} onChange={e => setData({...data, name: e.target.value})} placeholder="Titel" />
                        <input type="text" className="input input-bordered w-full text-sm" value={data.description || ''} onChange={e => setData({...data, description: e.target.value})} placeholder="Beschreibung" />
                    </div>
                </td>
                <td>
                    <select className="select select-bordered w-full mb-2" value={data.flatrate_tier} onChange={e => setData({...data, flatrate_tier: e.target.value})}>
                        <option value="web">Web</option>
                        <option value="print">Print</option>
                        <option value="original">Original</option>
                    </select>
                    <label className="cursor-pointer flex items-center gap-2">
                        <input type="checkbox" className="checkbox-primary checkbox checkbox-sm" checked={!!data.is_commercial} onChange={e => setData({...data, is_commercial: e.target.checked})} />
                        <span className="label-text text-xs leading-tight">Kommerzielle Lizenz</span>
                    </label>
                </td>
                <td className="text-right">
                    <div className="flex justify-end items-center gap-1">
                        <input type="number" step="any" className="input input-bordered w-24 text-right" value={data.base_price} onChange={e => setData({...data, base_price: parseFloat(e.target.value) || 0})} />
                        <span className="font-bold opacity-70">€</span>
                    </div>
                </td>
                <td className="text-right whitespace-nowrap">
                    <button onClick={() => setIsEditing(false)} className="btn btn-xs btn-ghost mr-1">Abbrechen</button>
                    <button onClick={handleSave} className="btn btn-xs btn-primary">Speichern</button>
                </td>
            </tr>
        );
    }

    return (
        <tr>
            <td>
                <div className="font-bold text-base">{uc.name}</div>
                <div className="text-sm opacity-70 mt-1">{uc.description || '-'}</div>
            </td>
            <td>
                <span className="badge badge-sm badge-ghost uppercase font-bold block w-fit">{uc.flatrate_tier}</span>
                {uc.is_commercial && <div className="text-xs text-warning font-bold mt-1">Kommerziell</div>}
            </td>
            <td className="text-right font-mono font-bold text-base">{formatMoney(uc.base_price)}</td>
            <td className="text-right">
                <div className="flex justify-end gap-1">
                    <button onClick={() => setIsEditing(true)} className="btn btn-xs btn-ghost btn-square"><span className="iconify mdi--pencil text-base"></span></button>
                    <button onClick={() => onDelete(uc.id)} className="btn btn-xs btn-ghost btn-square text-error"><span className="iconify mdi--trash-can text-base"></span></button>
                </div>
            </td>
        </tr>
    );
}

function ModifierRow({ mod, onSave, onDelete }: { mod: LicenseModifier, onSave: (id: string, data: Partial<LicenseModifier>) => Promise<void>, onDelete: (id: string) => void }) {
    const [isEditing, setIsEditing] = useState(false);
    const [data, setData] = useState<Partial<LicenseModifier>>({ ...mod });
    const { showToast } = useUI();

    const handleSave = async () => {
        try {
            await onSave(mod.id, { ...data, percent_surcharge: Number(data.percent_surcharge) });
            showToast('success', 'Zuschlag aktualisiert');
            setIsEditing(false);
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    if (isEditing) {
        return (
            <tr>
                <td>
                    <div className="flex flex-col gap-2">
                        <input type="text" className="input input-bordered w-full" value={data.name || ''} onChange={e => setData({...data, name: e.target.value})} placeholder="Titel" />
                        <input type="text" className="input input-bordered w-full text-sm" value={data.description || ''} onChange={e => setData({...data, description: e.target.value})} placeholder="Beschreibung" />
                    </div>
                </td>
                <td>
                    <label className="cursor-pointer flex items-center gap-2">
                        <input type="checkbox" className="checkbox-primary checkbox" checked={data.is_included_in_flatrate} onChange={e => setData({...data, is_included_in_flatrate: e.target.checked})} />
                        <span className="label-text text-sm leading-tight">In Flatrates<br/>inkludiert</span>
                    </label>
                </td>
                <td className="text-right">
                    <div className="flex justify-end items-center gap-1">
                        <span className="font-bold opacity-70">+</span>
                        <input type="number" step="any" className="input input-bordered w-20 text-right" value={data.percent_surcharge} onChange={e => setData({...data, percent_surcharge: parseFloat(e.target.value) || 0})} />
                        <span className="font-bold opacity-70">%</span>
                    </div>
                </td>
                <td className="text-right whitespace-nowrap">
                    <button onClick={() => setIsEditing(false)} className="btn btn-xs btn-ghost mr-1">Abbrechen</button>
                    <button onClick={handleSave} className="btn btn-xs btn-primary">Speichern</button>
                </td>
            </tr>
        );
    }

    return (
        <tr>
            <td>
                <div className="font-bold text-base">{mod.name}</div>
                <div className="text-sm opacity-70 mt-1">{mod.description || '-'}</div>
            </td>
            <td>
                {mod.is_included_in_flatrate 
                    ? <div className="text-sm font-bold opacity-70 flex items-center gap-1"><span className="iconify mdi--check text-primary"></span> Inkludiert</div>
                    : <div className="text-sm opacity-50 flex items-center gap-1"><span className="iconify mdi--minus"></span> Kostenpflichtig</div>
                }
            </td>
            <td className="text-right font-mono font-bold text-base">+{Number(mod.percent_surcharge).toFixed(0)} %</td>
            <td className="text-right">
                <div className="flex justify-end gap-1">
                    <button onClick={() => setIsEditing(true)} className="btn btn-xs btn-ghost btn-square"><span className="iconify mdi--pencil text-base"></span></button>
                    <button onClick={() => onDelete(mod.id)} className="btn btn-xs btn-ghost btn-square text-error"><span className="iconify mdi--trash-can text-base"></span></button>
                </div>
            </td>
        </tr>
    );
}

export default function LicenseCatalogSettings() {
    const { catalog, isLoading, createUseCase, updateUseCase, deleteUseCase, createModifier, updateModifier, deleteModifier } = useLicenseCatalog();
    const { showToast, confirm } = useUI();

    const [newUc, setNewUc] = useState({ name: '', description: '', base_price: '', flatrate_tier: 'web', is_commercial: false });
    const [newMod, setNewMod] = useState({ name: '', description: '', percent_surcharge: '', is_included_in_flatrate: false });

    const handleAddUseCase = async () => {
        if (!newUc.name || !newUc.base_price) { showToast('error', 'Name und Preis sind Pflichtfelder'); return; }
        try {
            await createUseCase({ name: newUc.name, description: newUc.description, base_price: Math.round(parseFloat(newUc.base_price) * 100), flatrate_tier: newUc.flatrate_tier, is_commercial: newUc.is_commercial });
            showToast('success', 'Kategorie hinzugefügt');
            setNewUc({ name: '', description: '', base_price: '', flatrate_tier: 'web', is_commercial: false });
        } catch { showToast('error', 'Fehler beim Speichern'); }
    };

    const handleDeleteUC = async (id: string) => {
        if (await confirm({ title: 'Löschen?', message: 'Kategorie wirklich löschen?', confirmColor: 'error' })) {
            await deleteUseCase(id);
            showToast('success', 'Kategorie gelöscht');
        }
    };

    const handleAddModifier = async () => {
        if (!newMod.name || !newMod.percent_surcharge) { showToast('error', 'Name und Zuschlag sind Pflichtfelder'); return; }
        try {
            await createModifier({ name: newMod.name, description: newMod.description, percent_surcharge: parseFloat(newMod.percent_surcharge), is_included_in_flatrate: newMod.is_included_in_flatrate });
            showToast('success', 'Zuschlag hinzugefügt');
            setNewMod({ name: '', description: '', percent_surcharge: '', is_included_in_flatrate: false });
        } catch { showToast('error', 'Fehler beim Speichern'); }
    };

    const handleDeleteMod = async (id: string) => {
        if (await confirm({ title: 'Löschen?', message: 'Zuschlag wirklich löschen?', confirmColor: 'error' })) {
            await deleteModifier(id);
            showToast('success', 'Zuschlag gelöscht');
        }
    };

    if (isLoading) return <div className="p-8 text-center"><span className="loading loading-spinner text-primary"></span></div>;

    return (
        <div className="card bg-base-100 border border-base-300 mt-8 shadow-sm">
            <div className="card-body p-6 md:p-8">
                <h2 className="card-title text-2xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--format-list-checks text-primary text-3xl"></span> Lizenz-Katalog (RSV Modell)
                </h2>
                <p className="text-sm opacity-70 mb-8 max-w-3xl">
                    Definiere die Basis-Kategorien (Grundhonorare) und die modularen Aufschläge (Zuschläge), die deine Kunden im Checkout auswählen können.
                    Alle Einträge werden den Kunden zur Auswahl angeboten.
                </p>

                <div className="flex flex-col gap-12">
                    
                    {/* Sektion 1: USE CASES */}
                    <div className="bg-base-100">
                        <h3 className="font-bold text-xl text-primary mb-4 flex items-center gap-2">
                            <span className="iconify mdi--numeric-1-box-outline"></span> Kategorien (Grundhonorare)
                        </h3>
                        <div className="overflow-x-auto rounded-box border border-base-300 mb-4">
                            <table className="table table-zebra w-full">
                                <thead className="bg-base-200">
                                    <tr>
                                        <th className="w-[30%]">Titel & Beschreibung</th>
                                        <th className="w-[20%]">Flatrate-Verknüpfung</th>
                                        <th className="text-right w-[20%]">Basispreis (Netto)</th>
                                        <th className="text-right w-[150px]">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {catalog?.use_cases.map(uc => (
                                        <UseCaseRow key={uc.id} uc={uc} onSave={updateUseCase} onDelete={handleDeleteUC} />
                                    ))}
                                    {(!catalog?.use_cases || catalog.use_cases.length === 0) && <tr><td colSpan={4} className="text-center py-6 opacity-50">Keine Kategorien vorhanden.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        
                        {/* Add New Use Case */}
                        <div className="bg-base-200/50 p-4 rounded-box border border-base-300 flex flex-col md:flex-row gap-3 items-start md:items-end">
                            <div className="form-control flex-1 w-full">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Neuer Titel</span></label>
                                <input type="text" placeholder="z.B. PR & Social Media" value={newUc.name} onChange={e=>setNewUc({...newUc, name: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <div className="form-control flex-1 w-full">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Beschreibung (Optional)</span></label>
                                <input type="text" placeholder="Details zur Lizenz..." value={newUc.description} onChange={e=>setNewUc({...newUc, description: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <div className="form-control w-full md:w-32">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Flatrate-Basis</span></label>
                                <select value={newUc.flatrate_tier} onChange={e=>setNewUc({...newUc, flatrate_tier: e.target.value})} className="select select-bordered w-full mb-2">
                                    <option value="web">Web</option>
                                    <option value="print">Print</option>
                                    <option value="original">Original</option>
                                </select>
                                <label className="cursor-pointer flex items-center gap-2">
                                    <input type="checkbox" className="checkbox-primary checkbox checkbox-sm" checked={newUc.is_commercial} onChange={e=>setNewUc({...newUc, is_commercial: e.target.checked})} />
                                    <span className="label-text text-xs leading-tight">Kommerziell</span>
                                </label>
                            </div>
                            <div className="form-control w-full md:w-32">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Preis (Netto €)</span></label>
                                <input type="number" step="any" placeholder="z.B. 150" value={newUc.base_price} onChange={e=>setNewUc({...newUc, base_price: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <button onClick={handleAddUseCase} className="btn btn-primary w-full md:w-auto"><span className="iconify mdi--plus"></span> Hinzufügen</button>
                        </div>
                    </div>

                    {/* Sektion 2: MODIFIERS */}
                    <div className="bg-base-100">
                        <h3 className="font-bold text-xl text-primary mb-4 flex items-center gap-2">
                            <span className="iconify mdi--numeric-2-box-outline"></span> Zuschläge (Aufschläge in %)
                        </h3>
                        <div className="overflow-x-auto rounded-box border border-base-300 mb-4">
                            <table className="table table-zebra w-full">
                                <thead className="bg-base-200">
                                    <tr>
                                        <th className="w-[40%]">Titel & Beschreibung</th>
                                        <th className="w-[20%]">Flatrate Verhalten</th>
                                        <th className="text-right w-[15%]">Aufschlag (%)</th>
                                        <th className="text-right w-[150px]">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {catalog?.modifiers.map(mod => (
                                        <ModifierRow key={mod.id} mod={mod} onSave={updateModifier} onDelete={handleDeleteMod} />
                                    ))}
                                    {(!catalog?.modifiers || catalog.modifiers.length === 0) && <tr><td colSpan={4} className="text-center py-6 opacity-50">Keine Zuschläge vorhanden.</td></tr>}
                                </tbody>
                            </table>
                        </div>

                        {/* Add New Modifier */}
                        <div className="bg-base-200/50 p-4 rounded-box border border-base-300 flex flex-col md:flex-row gap-3 items-start md:items-end">
                            <div className="form-control flex-1 w-full">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Neuer Zuschlag</span></label>
                                <input type="text" placeholder="z.B. Titelseite" value={newMod.name} onChange={e=>setNewMod({...newMod, name: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <div className="form-control flex-1 w-full">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Beschreibung (Optional)</span></label>
                                <input type="text" placeholder="Details..." value={newMod.description} onChange={e=>setNewMod({...newMod, description: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <div className="form-control w-full md:w-auto self-center md:self-end mb-1">
                                <label className="cursor-pointer flex items-center gap-2">
                                    <input type="checkbox" className="checkbox-primary checkbox" checked={newMod.is_included_in_flatrate} onChange={e=>setNewMod({...newMod, is_included_in_flatrate: e.target.checked})} />
                                    <span className="label-text text-sm leading-tight font-medium">In Flatrates<br/>inkludieren</span>
                                </label>
                            </div>
                            <div className="form-control w-full md:w-32">
                                <label className="label py-1"><span className="label-text text-sm font-bold">Aufschlag (%)</span></label>
                                <input type="number" step="any" placeholder="z.B. 100" value={newMod.percent_surcharge} onChange={e=>setNewMod({...newMod, percent_surcharge: e.target.value})} className="input input-bordered w-full" />
                            </div>
                            <button onClick={handleAddModifier} className="btn btn-primary w-full md:w-auto"><span className="iconify mdi--plus"></span> Hinzufügen</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    );
}
