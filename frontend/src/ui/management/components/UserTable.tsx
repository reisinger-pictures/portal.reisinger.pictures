import { UserDetailed } from '../../../logic/useUsers';

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
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Rollen</th>
                        <th>Rechte (Gruppen / Galerien)</th>
                        <th>Aktionen</th>
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
                                        <span key={r.id} className="badge badge-primary badge-sm">{r.name}</span>
                                    )) : <span className="text-sm opacity-50">Keine Rolle</span>}
                                </div>
                            </td>
                            <td className="text-sm opacity-80">
                                {(u.gallery_groups || []).length} Gruppen, {(u.galleries || []).length} Galerien
                            </td>
                            <td>
                                <button className="btn btn-xs btn-outline" onClick={() => onEdit(u)}>Bearbeiten</button>
                            </td>
                        </tr>
                    ))}
                    {filteredUsers?.length === 0 && (
                        <tr>
                            <td colSpan={5} className="text-center py-8 opacity-50">
                                Keine Nutzer gefunden, die"{searchTerm}" entsprechen.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
