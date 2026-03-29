import { useState } from 'react';
import {useSearchParams} from 'react-router-dom';
import {UserDetailed, useUsers} from '../../logic/useUsers';
import {flattenGroups, useProtectedGalleries} from '../../logic/useGalleries';
import DomainMappingTab from './components/DomainMappingTab';
import UserPermissionsModal from './components/UserPermissionsModal';
import CreateUserModal from './components/CreateUserModal';
import { useUI } from '../components/UIContext';

export default function ManagementUserView() {
    const {users, roles, mappings, createUser, updateUser, createMapping, deleteMapping} = useUsers();
    const {tree} = useProtectedGalleries();
    const { showToast } = useUI();

    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab = searchParams.get('tab') || 'users';

    const [editingUser, setEditingUser] = useState<UserDetailed | null>(null);
    const [searchTerm, setSearchTerm] = useState('');

    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const flatGroups = tree ? flattenGroups(tree.groups) : [];
    const flatGalleries = tree ? [...(tree.groups.flatMap(g => g.galleries || [])), ...(tree.root_galleries || [])] : [];

    const handleSaveUser = async (id: string, selRoles: string[], selGroups: string[], selGalleries: string[], canEditMeta: boolean) => {
        try {
            await updateUser(id, selRoles, selGroups, selGalleries, canEditMeta);
            showToast('success', 'Nutzerrechte gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern der Rechte.');
        }
        setEditingUser(null);
    };


    const filteredUsers = users?.filter(u =>
        u.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        u.email.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="p-10 max-w-6xl mx-auto w-full relative">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <h1 className="text-4xl font-bold">Benutzer &amp; Rechte</h1>
                {activeTab === 'users' && (
                    <button className="btn btn-primary" onClick={() => setIsCreateModalOpen(true)}>+ Neuen Nutzer
                        anlegen</button>
                )}
            </div>

            <div className="tabs tabs-box">
                <input
                    type="radio"
                    name="management_user_tabs"
                    className="tab font-semibold"
                    aria-label="Benutzerverwaltung"
                    checked={activeTab === 'users'}
                    onChange={() => setSearchParams({tab: 'users'})}
                />
                <div className="tab-content bg-base-100 border-base-300 p-6">
                    <div className="mb-4">
                        <div className="join w-full md:w-1/2 shadow-sm">
                            <input
                                type="text"
                                placeholder="Nutzer suchen (Name oder E-Mail)..."
                                className="input input-bordered join-item w-full bg-base-100"
                                value={searchTerm}
                                onChange={e => setSearchTerm(e.target.value)}
                            />
                            <button className="btn btn-square join-item" disabled>
                                <span className="iconify mdi--magnify text-xl"></span>
                            </button>
                        </div>
                    </div>

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
                                        {u.roles && u.roles.length > 0 ? u.roles.map(r => <span key={r.id}
                                                                                     className="badge badge-primary badge-sm mr-1">{r.name}</span>) :
                                            <span className="text-xs opacity-50">Keine Rolle</span>}
                                    </td>
                                    <td className="text-xs opacity-80">
                                        {(u.gallery_groups || []).length} Gruppen, {(u.galleries || []).length} Galerien
                                    </td>
                                    <td>
                                        <button className="btn btn-xs btn-outline"
                                                onClick={() => setEditingUser(u)}>Bearbeiten
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {filteredUsers?.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-center py-8 opacity-50">
                                        Keine Nutzer gefunden, die "{searchTerm}" entsprechen.
                                    </td>
                                </tr>
                            )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <input
                    type="radio"
                    name="management_user_tabs"
                    className="tab font-semibold"
                    aria-label="Domain-Mappings"
                    checked={activeTab === 'mappings'}
                    onChange={() => setSearchParams({tab: 'mappings'})}
                />
                <div className="tab-content bg-base-100 border-base-300 p-6">
                    <DomainMappingTab
                        mappings={mappings}
                        roles={roles}
                        flatGroups={flatGroups}
                        onCreateMapping={createMapping}
                        onDeleteMapping={deleteMapping}
                    />
                </div>
            </div>

            {editingUser && (
                <UserPermissionsModal
                    key={editingUser.id}
                    user={editingUser}
                    roles={roles}
                    flatGroups={flatGroups}
                    flatGalleries={flatGalleries}
                    onClose={() => setEditingUser(null)}
                    onSave={handleSaveUser}
                />
            )}

            <CreateUserModal 
                isOpen={isCreateModalOpen} 
                onClose={() => setIsCreateModalOpen(false)} 
                onCreate={createUser} 
            />
        </div>
    );
}
