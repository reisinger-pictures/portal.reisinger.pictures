import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useCallback, useState} from 'react';
import {Role, UserDetailed, UserRole} from '../../../logic/useUsers';
import {FlatGroup, Gallery} from '../../../logic/useGalleries';

interface UserPermissionsModalProps {
    user: UserDetailed;
    roles?: Role[];
    flatGroups: FlatGroup[];
    flatGalleries: Gallery[];
    onClose: () => void;
    onSave: (id: string, roles: string[], groups: string[], galleries: string[], canEditMeta: boolean, flatrateLevel: string, brand: string | null, canPurchaseUpgrades: boolean) => Promise<void>;
}

const SUPER_ADMIN_ROLE_NAME = UserRole.SUPER_ADMIN;

export default function UserPermissionsModal({
                                                 user,
                                                 roles,
                                                 flatGroups,
                                                 flatGalleries,
                                                 onClose,
                                                 onSave
                                             }: UserPermissionsModalProps) {
    const [selRoles, setSelRoles] = useState<string[]>(user.roles.map(r => r.id));
    const [selGroups, setSelGroups] = useState<string[]>(user.gallery_groups.map(g => g.id));
    const [selGalleries, setSelGalleries] = useState<string[]>(user.galleries.map(g => g.id));

    const [canEditMeta, setCanEditMeta] = useState<boolean>(user.can_edit_metadata || false);
    const [flatrateLevel, setFlatrateLevel] = useState<string>(user.flatrate_level || 'none');
    const [brand, setBrand] = useState<string | null>(user.brand ?? null);
    const [canPurchaseUpgrades, setCanPurchaseUpgrades] = useState<boolean>(user.can_purchase_upgrades ?? false);

    const selectedRoleNames = (roles ?? [])
        .filter(r => selRoles.includes(r.id))
        .map(r => r.name);
    const isSuperAdmin = selectedRoleNames.includes(SUPER_ADMIN_ROLE_NAME);
    const effectiveBrand: string | null = isSuperAdmin ? null : brand;

    // When toggling roles, adjust brand for the super-admin transition.
    const toggleItem = useCallback((arr: string[], setArr: React.Dispatch<React.SetStateAction<string[]>>, id: string) => {
        const newArr = arr.includes(id) ? arr.filter(x => x !== id) : [...arr, id];
        setArr(newArr);

        // Detect super-admin role toggle — only if we know the role names.
        if (!roles) return;
        const toggledRole = roles.find(r => r.id === id);
        if (!toggledRole) return;

        const wouldBeSuperAdmin = (toggledRole.name === SUPER_ADMIN_ROLE_NAME && !arr.includes(id))
            || (toggledRole.name !== SUPER_ADMIN_ROLE_NAME && newArr.some(rId => {
                const r = roles.find(role => role.id === rId);
                return r?.name === SUPER_ADMIN_ROLE_NAME;
            }));

        if (wouldBeSuperAdmin) {
            // Switching TO super-admin → force cross-brand.
            setBrand(null);
        } else if (toggledRole.name === SUPER_ADMIN_ROLE_NAME && arr.includes(id)) {
            // Switching FROM super-admin → reset to default brand.
            setBrand('rp');
        }
    }, [roles]);

    const handleSave = () => {
        onSave(user.id, selRoles, selGroups, selGalleries, canEditMeta, flatrateLevel, effectiveBrand, canPurchaseUpgrades);
    };

    const userNameEdit = user.name;
    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-4xl relative">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-2xl mb-1"><Trans>{userNameEdit} bearbeiten</Trans></h3>
                <p className="opacity-70 mb-6 flex items-center gap-2">
                    <span className="iconify mdi--email-outline"></span> {user.email}
                </p>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div className="form-control bg-base-200 p-4 rounded-box border border-base-300">
                        <label className="cursor-pointer label justify-start gap-4 hover:opacity-80 transition-opacity">
                            <input type="checkbox" checked={canEditMeta}
                                   onChange={e => setCanEditMeta(e.target.checked)}
                                   className="checkbox checkbox-primary shrink-0"/>
                            <div>
                                <span className="label-text font-bold block"><Trans>Metadaten bearbeiten (IPTC)</Trans></span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1"><Trans>Erlaubt dem User das Ändern von Bildbeschreibungen.</Trans></span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control">
                        <label className="label"><span
                            className="label-text font-bold"><Trans>Inkludiertes Flatrate-Level</Trans></span></label>
                        <select className="select select-bordered w-full" value={flatrateLevel}
                                onChange={e => setFlatrateLevel(e.target.value)}>
                            <option value="none"><Trans>Keine Flatrate (0.00x)</Trans></option>
                            <option value="web"><Trans>Web / Social-Media bis 2560px (1.00x)</Trans></option>
                            <option value="print"><Trans>Print bis 4000px (2.00x)</Trans></option>
                            <option value="original"><Trans>Original Master-File (4.00x)</Trans></option>
                        </select>
                        <span className="label-text-alt opacity-70 mt-1 pl-1"><Trans>Bestimmt, welche Auflösungen direkt ohne Checkout geladen werden können.</Trans></span>
                    </div>

                    <div className="form-control bg-base-200 p-4 rounded-box border border-base-300">
                        <label className="cursor-pointer label justify-start gap-4 hover:opacity-80 transition-opacity">
                            <input type="checkbox" checked={canPurchaseUpgrades}
                                   onChange={e => setCanPurchaseUpgrades(e.target.checked)}
                                   className="checkbox checkbox-primary shrink-0"/>
                            <div>
                                <span className="label-text font-bold block"><Trans>Upgrades kaufen erlaubt</Trans></span>
                                <span className="label-text-alt opacity-70 leading-tight block mt-1"><Trans>Erlaubt dem User kostenpflichtige Upgrades (höhere Auflösungen) im Checkout zu erwerben.</Trans></span>
                            </div>
                        </label>
                    </div>
                </div>

                <div className="form-control bg-base-200 p-4 rounded-box border border-base-300">
                    <label className="label"><span className="label-text font-bold"><Trans>Brand-Zuweisung</Trans></span></label>
                    <select
                        className="select select-bordered w-full"
                        value={effectiveBrand ?? ''}
                        disabled={isSuperAdmin}
                        onChange={e => setBrand(e.target.value || null)}
                    >
                        <option value=""><Trans>Übergreifend (cross-brand, nur Super-Admin)</Trans></option>
                        <option value="rp"><Trans>Portal (reisinger.pictures)</Trans></option>
                    </select>
                    <span className="label-text-alt opacity-70 mt-1 pl-1">
                        {isSuperAdmin
                            ? t`Super-Administratoren sind immer cross-brand (keine Brand-Bindung).`
                            : t`Jeder Account benötigt eine Brand-Zuweisung (U-02).`}
                    </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10"><Trans>Rollen</Trans></h4>
                        {roles?.map(r => (
                            <label key={r.id} className="label cursor-pointer justify-start gap-3 p-2 rounded hover:bg-base-200 transition-colors">
                                <input type="checkbox" checked={selRoles.includes(r.id)}
                                       onChange={() => toggleItem(selRoles, setSelRoles, r.id)}
                                       className="checkbox checkbox-primary shrink-0"/>
                                <span className="label-text">{r.name}</span>
                            </label>
                        ))}
                        {(!roles || roles.length === 0) &&
                            <p className="text-sm opacity-50 italic"><Trans>Keine Rollen zuweisbar.</Trans></p>}
                    </div>
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10"><Trans>Gruppen</Trans></h4>
                        {flatGroups.map(g => (
                            <label key={g.id} className="label cursor-pointer justify-start gap-3 p-2 rounded hover:bg-base-200 transition-colors">
                                <input type="checkbox" checked={selGroups.includes(g.id)}
                                       onChange={() => toggleItem(selGroups, setSelGroups, g.id)}
                                       className="checkbox checkbox-primary shrink-0"/>
                                <span className="label-text">{'- '.repeat(g.depth)}{g.name}</span>
                            </label>
                        ))}
                    </div>
                    <div className="border border-base-300 rounded-box p-4 h-64 overflow-y-auto">
                        <h4 className="font-bold mb-2 sticky top-0 bg-base-100 z-10"><Trans>Galerien</Trans></h4>
                        {flatGalleries.map(g => (
                            <label key={g.id} className="label cursor-pointer justify-start gap-3 p-2 rounded hover:bg-base-200 transition-colors">
                                <input type="checkbox" checked={selGalleries.includes(g.id)}
                                       onChange={() => toggleItem(selGalleries, setSelGalleries, g.id)}
                                       className="checkbox checkbox-primary shrink-0"/>
                                <span className="label-text truncate" title={g.name}>{g.name}</span>
                            </label>
                        ))}
                    </div>
                </div>

                <div className="modal-action col-span-full mt-6">
                    <button className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                    <button className="btn btn-primary" onClick={handleSave}><Trans>Speichern</Trans></button>
                </div>
            </div>
            <div className="modal-backdrop"></div>
        </div>
    );
}
