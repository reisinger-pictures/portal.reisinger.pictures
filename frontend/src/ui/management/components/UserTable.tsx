import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { UserDetailed, UserRole } from '../../../logic/useUsers';

const roleLabels: Record<UserRole, string> = {
    [UserRole.SUPER_ADMIN]: t`Super-Admin`,
    [UserRole.ADMIN]: t`Administrator`,
    [UserRole.PHOTOGRAPHER]: t`Fotograf`,
    [UserRole.CUSTOMER_MANAGER]: t`Kundenbetreuer`,
    [UserRole.POWER_USER]: t`Power-User`,
    [UserRole.CLIENT]: t`Kunde`,
};

interface UserTableProps {
    users?: UserDetailed[];
    searchTerm: string;
    onEdit: (user: UserDetailed) => void;
}

export default function UserTable({ users, searchTerm, onEdit }: UserTableProps) {
    const filteredUsers = users?.filter(u =>
        u.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        u.email.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="overflow-x-auto rounded-box border border-base-300">
            <table className="table table-zebra w-full">
                <thead>
                    <tr>
                        <th><Trans>Name</Trans></th>
                        <th><Trans>E-Mail</Trans></th>
                        <th><Trans>Rollen</Trans></th>
                        <th><Trans>Rechte (Gruppen / Galerien)</Trans></th>
                        <th><Trans>Upgrades</Trans></th>
                        <th><Trans>Aktionen</Trans></th>
                    </tr>
                </thead>
                <tbody>
                    {filteredUsers?.map(u => (
                        <tr key={u.id}>
                            <td className="font-bold">{u.name}</td>
                            <td>{u.email}</td>
                            <td>
                                <div className="flex flex-wrap gap-1">
                                    {u.roles && u.roles.length > 0 ? u.roles.map(r => (
                                        <span key={r.id} className="badge badge-primary badge-sm">{roleLabels[r.name] || r.name}</span>
                                    )) : <span className="text-sm opacity-50"><Trans>Keine Rolle</Trans></span>}
                                </div>
                            </td>
                            <td className="text-sm opacity-80">
                                {(u.gallery_groups || []).length} Gruppen, {(u.galleries || []).length} Galerien
                            </td>
                            <td>{u.can_purchase_upgrades ? <span className="badge badge-success badge-sm"><Trans>Ja</Trans></span> : <span className="text-sm opacity-50"><Trans>Nein</Trans></span>}</td>
                            <td>
                                <button className="btn btn-xs btn-outline" onClick={() => onEdit(u)}><Trans>Bearbeiten</Trans></button>
                            </td>
                        </tr>
                    ))}
                    {filteredUsers?.length === 0 && (
                        <tr>
                            <td colSpan={6} className="text-center py-8 opacity-50">
                                <Trans>Keine Nutzer gefunden, die "{searchTerm}" entsprechen.</Trans>
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
