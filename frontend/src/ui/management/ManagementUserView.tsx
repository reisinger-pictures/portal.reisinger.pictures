import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import {UserDetailed, useUsers, UserRole} from '../../logic/useUsers';
import {flattenGroups} from '../../logic/utils';
import {flattenTreeGalleries, useProtectedGalleries} from '../../logic/useGalleries';
import {usePermissions} from '../../logic/usePermissions';
import UserPermissionsModal from './components/UserPermissionsModal';
import CreateUserModal from './components/CreateUserModal';
import UserTable from './components/UserTable';
import { useUI } from '../components/UIContext';

// D-51/D-56: `UserController::update` refuses a role write whose editor
// snapshot no longer matches the roles it reads under the shared FTP account
// lock (409). Reopening the dialog is what makes that conflict recoverable:
// `useUsers.updateUser` refreshes the list even on failure, so the next open
// captures the current role set as its snapshot.
const ROLE_CONFLICT_FRAGMENT = 'Rollen wurden inzwischen geändert';

function isRoleSnapshotConflict(error: unknown): boolean {
    return typeof error === 'object'
        && error !== null
        && 'status' in error
        && error.status === 409
        && 'message' in error
        && typeof error.message === 'string'
        && error.message.includes(ROLE_CONFLICT_FRAGMENT);
}

export default function ManagementUserView() {
    const {isSuperAdmin, isAdmin, isOrgAdmin, orgScope} = usePermissions();
    const {users, roles,  createUser, updateUser, } = useUsers();
    const {tree} = useProtectedGalleries();
    const { showToast } = useUI();

    const [editingUser, setEditingUser] = useState<UserDetailed | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const flatGroups = tree ? flattenGroups(tree.groups) : [];
    const flatGalleries = tree ? flattenTreeGalleries(tree) : [];

    // SEC-20261018-02: an org admin may only assign galleries and groups that
    // lie inside the organization's delegation scope — the server rejects the
    // rest. The tree above is permission-filtered but includes the actor's
    // personal grants, so the lists are narrowed explicitly. Fails closed: no
    // server scope means no offered assignments.
    //
    // Groups are narrowed with `assignable_gallery_group_ids`, not the broad
    // `gallery_group_ids`: `UserController::update` checks the narrow set, and a
    // photographer who is also an org admin still receives the broad management
    // tree above. Filtering with the broad set would offer groups the server
    // answers with 403.
    const assignableGroupIds = orgScope?.assignable_gallery_group_ids;
    const selectableGroups = isOrgAdmin
        ? flatGroups.filter(group => assignableGroupIds?.includes(group.id) ?? false)
        : flatGroups;
    // D-43: a direct gallery assignment carries delivery galleries only. A
    // selection gallery is a private rating surface and reaches a client
    // through an invite, not through a direct grant — the API rejects a newly
    // requested non-delivery id. The org-admin branch is already delivery-only
    // through the server-resolved scope; the studio branch is narrowed here so
    // the editor does not offer what the API refuses.
    //
    // A gallery that is already assigned to the edited user stays visible and
    // selected (see `UserPermissionsModal`), so an unrelated save neither
    // revokes a legacy grant nor is blocked by it.
    const selectableGalleries = isOrgAdmin
        ? flatGalleries.filter(gallery => orgScope?.gallery_ids.includes(gallery.id) ?? false)
        : flatGalleries.filter(gallery => gallery.type === 'delivery');

    const allowedRoles = isSuperAdmin
        ? roles
        : (isAdmin
            ? roles?.filter(r => r.name !== UserRole.SUPER_ADMIN)
            : roles?.filter(r => [UserRole.POWER_USER, UserRole.CLIENT, UserRole.ORG_ADMIN].includes(r.name)));

    const handleSaveUser = async (id: string, expectedRoleIds: string[], selRoles: string[], selGroups: string[], selGalleries: string[], canEditMeta: boolean, flatrateLevel: string, brand: string | null, canPurchaseUpgrades: boolean) => {
        try {
            await updateUser(id, expectedRoleIds, selRoles, selGroups, selGalleries, canEditMeta, flatrateLevel, brand, canPurchaseUpgrades);
            showToast('success', t`Nutzerrechte gespeichert.`);
        } catch (error: unknown) {
            if (isRoleSnapshotConflict(error)) {
                // Close the editor so the mount-time role snapshot cannot be
                // submitted again. The refreshed list hands the reopened modal
                // the current roles, and a deliberate retry can then proceed.
                setEditingUser(null);
                showToast('error', t`Die Rollen wurden inzwischen geändert. Der Dialog wurde geschlossen – bitte erneut öffnen und die Rechte erneut speichern.`);
            } else {
                showToast('error', t`Fehler beim Speichern der Rechte.`);
            }
            throw error;
        }
        setEditingUser(null);
    };

    return (
        <div className="p-10 max-w-6xl mx-auto w-full relative">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <h1 className="text-4xl font-bold"><Trans>Benutzer & Rechte</Trans></h1>
                <button className="btn btn-primary" onClick={() => setIsCreateModalOpen(true)}>+ <Trans>Neuen Nutzer anlegen</Trans></button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6">
                <div className="mb-4">
                    <div className="join w-full md:w-1/2 shadow-sm">
                        <input
                            type="text"
                            placeholder={t`Nutzer suchen (Name oder E-Mail)...`}
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
                    isOrgAdmin={isOrgAdmin}
                    flatGroups={selectableGroups}
                    flatGalleries={selectableGalleries}
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
