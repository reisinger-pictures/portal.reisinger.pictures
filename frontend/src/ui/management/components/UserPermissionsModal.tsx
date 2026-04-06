import { useState } from 'react';
import {Role, UserDetailed} from '../../../logic/useUsers';
import {FlatGroup, Gallery} from '../../../logic/useGalleries';

interface UserPermissionsModalProps {
    user: UserDetailed;
    roles?: Role[];
    flatGroups: FlatGroup[];
    flatGalleries: Gallery[];
    onClose: () => void;
    onSave: (id: string, roles: string[], groups: string[], galleries: string[], canEditMeta: boolean, flatrateLevel: string) => Promise<void>;
}

export default function UserPermissionsModal({ user, roles, flatGroups, flatGalleries, onClose, onSave }: UserPermissionsModalProps) {
    const [selRoles, setSelRoles] = useState<string[]>(user.roles.map(r => r.id));
    const [selGroups, setSelGroups] = useState<string[]>(user.gallery_groups.map(g => g.id));
    const [selGalleries, setSelGalleries] = useState<string[]>(user.galleries.map(g => g.id));
    
    const [canEditMeta, setCanEditMeta] = useState<boolean>(user.can_edit_metadata || false);
    const [flatrateLevel, setFlatrateLevel] = useState<string>(user.flatrate_level || 'none');

    const toggleItem = (arr: string[], setArr: React.Dispatch<React.SetStateAction<string[]>>, id: string) => {
        setArr(arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id]);
    };

    const handleSave = () => {
        onSave(user.id, selRoles, selGroups, selGalleries, canEditMeta, flatrateLevel);
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-4xl relative">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-2xl mb-1">{user.name} bearbeiten</h3>
                <p className="opacity-70 text-sm mb-6 flex items-center gap-2">
                    <span className="iconify mdi--email-outline"></span> {user.email}
                </p>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div className="form-control bg-base-200 p-4 rounded-box border border-base-300">
                        <label className="cursor-pointer label justify-start gap-4">
                            <input type="checkbox" checked={canEditMeta} onChange={e => setCanEditMeta(e.target.checked)} className="checkbox checkbox-primary"/>
                            <div>
                                <span className="label-text font-bold block">Metadaten bearbeiten (IPTC)</span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1">Erlaubt dem User das Ändern von Bildbeschreibungen.</span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Inkludiertes Flatrate-Level</span></label>
                        <select className="select select-bordered w-full" value={flatrateLevel} onChange={e => setFlatrateLevel(e.target.value)}>
                            <option value="none">Keine Flatrate (0.00x)</option>
                            <option value="web">Web / Social-Media bis 2560px (1.00x)</option>
                            <option value="print">Print bis 4000px (2.00x)</option>
                            <option value="original">Original Master-File (4.00x)</option>
                        </select>
                        <span className="label-text-alt opacity-70 mt-1 pl-1">Bestimmt, welche Auflösungen direkt ohne Checkout geladen werden können.</span>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10">Rollen</h4>
                        {roles?.map(r => (
                            <label key={r.id} className="label cursor-pointer justify-start gap-3 p-1">
                                <input type="checkbox" checked={selRoles.includes(r.id)} onChange={() => toggleItem(selRoles, setSelRoles, r.id)} className="checkbox checkbox-sm checkbox-primary"/>
                                <span className="label-text">{r.name}</span>
                            </label>
                        ))}
                        {(!roles || roles.length === 0) && <p className="text-xs opacity-50 italic">Keine Rollen zuweisbar.</p>}
                    </div>
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10">Gruppen</h4>
                        {flatGroups.map(g => (
                            <label key={g.id} className="label cursor-pointer justify-start gap-3 p-1">
                                <input type="checkbox" checked={selGroups.includes(g.id)} onChange={() => toggleItem(selGroups, setSelGroups, g.id)} className="checkbox checkbox-sm checkbox-primary"/>
                                <span className="label-text">{'- '.repeat(g.depth)}{g.name}</span>
                            </label>
                        ))}
                    </div>
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10">Galerien</h4>
                        {flatGalleries.map(g => (
                            <label key={g.id} className="label cursor-pointer justify-start gap-3 p-1">
                                <input type="checkbox" checked={selGalleries.includes(g.id)} onChange={() => toggleItem(selGalleries, setSelGalleries, g.id)} className="checkbox checkbox-sm checkbox-primary"/>
                                <span className="label-text truncate" title={g.name}>{g.name}</span>
                            </label>
                        ))}
                    </div>
                </div>
                
                <div className="modal-action mt-6">
                    <button className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                    <button className="btn btn-primary" onClick={handleSave}>Speichern</button>
                </div>
            </div>
            <div className="modal-backdrop"></div>
        </div>
    );
}
