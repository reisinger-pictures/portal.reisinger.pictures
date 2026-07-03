import { useState } from 'react';
import {UserDetailed, useUsers, UserRole} from '../../logic/useUsers';
import {flattenGroups} from '../../logic/utils';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {usePermissions} from '../../logic/usePermissions';
import UserPermissionsModal from './components/UserPermissionsModal';
import CreateUserModal from './components/CreateUserModal';
import UserTable from './components/UserTable';
import { useUI } from '../components/UIContext';

export default function ManagementUserView() {
    const {isSuperAdmin, isAdmin} = usePermissions();
    const {users, roles,  createUser, updateUser, } = useUsers();
    const {tree} = useProtectedGalleries();
    const { showToast } = useUI();

    
    const [editingUser, setEditingUser] = useState<UserDetailed | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const flatGroups = tree ? flattenGroups(tree.groups) : [];
    const flatGalleries = tree ? [...(tree.groups.flatMap(g => g.galleries || [])), ...(tree.root_galleries || [])] : [];

    const allowedRoles = isSuperAdmin
        ? roles
        : (isAdmin
            ? roles?.filter(r => r.name !== UserRole.SUPER_ADMIN)
            : roles?.filter(r => [UserRole.POWER_USER, UserRole.CLIENT, UserRole.CUSTOMER_MANAGER].includes(r.name)));

    const handleSaveUser = async (id: string, selRoles: string[], selGroups: string[], selGalleries: string[], canEditMeta: boolean, flatrateLevel: string, brand: 'rp' | 'srp' | null, canPurchaseUpgrades: boolean) => {
        try {
            await updateUser(id, selRoles, selGroups, selGalleries, canEditMeta, flatrateLevel, brand, canPurchaseUpgrades);
            showToast('success', 'Nutzerrechte gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern der Rechte.');
        }
        setEditingUser(null);
    };

    return (
        <div className="p-10 max-w-6xl mx-auto w-full relative">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <h1 className="text-4xl font-bold">Benutzer &amp; Rechte</h1>
                <button className="btn btn-primary" onClick={() => setIsCreateModalOpen(true)}>+ Neuen Nutzer anlegen</button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6">
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

                <UserTable users={users} searchTerm={searchTerm} onEdit={setEditingUser} />
            </div>

            {editingUser && (
                <UserPermissionsModal
                    key={editingUser.id}
                    user={editingUser}
                    roles={allowedRoles}
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
