import { useAuth, type AuthMeUser } from './useAuth';
import type { OrgScope } from '../api';

export interface Permissions {
    isStaff: boolean;
    isSuperAdmin: boolean;
    isAdmin: boolean;
    isPhotographer: boolean;
    isOrgAdmin: boolean;
    canEditMetadata: boolean;
    isPowerUser: boolean;
    canAccessB2BFeatures: boolean;
    canAccessProjectsBoard: boolean;
    canAccessProductionBoard: boolean;
    showOrgsSection: boolean;
    showCRM: boolean;
    showInvoicing: boolean;
    showPayouts: boolean;
    /**
     * The server-resolved delegation boundary of an org admin, or `null` for
     * every other actor. Use it to keep the UI from offering assignments the
     * server rejects (SEC-20261018-02). Optional because most permission
     * fixtures predate the field and only the org-admin views read it; the
     * server remains the enforcement point.
     */
    orgScope?: OrgScope | null;
}

export function computePermissions(user: AuthMeUser | null | undefined): Permissions {
    const isSuperAdmin = !!user?.is_super_admin;
    const isAdmin = !!user?.is_admin;
    const isPhotographer = !!user?.is_photographer;
    const isOrgAdmin = !!user?.is_org_admin;
    const isStaff = isSuperAdmin || isAdmin || isPhotographer || isOrgAdmin;
    const canEditMetadata = !!user?.can_edit_metadata;
    const isPowerUser = !!user?.is_power_user;
    const canAccessB2BFeatures = isStaff;

    return {
        isStaff,
        isSuperAdmin,
        isAdmin,
        isPhotographer,
        isOrgAdmin,
        canEditMetadata,
        isPowerUser,
        canAccessB2BFeatures,
        canAccessProjectsBoard: isAdmin,
        canAccessProductionBoard: isPhotographer || isSuperAdmin,
        showOrgsSection: canAccessB2BFeatures,
        showCRM: canAccessB2BFeatures,
        showInvoicing: canAccessB2BFeatures,
        showPayouts: isSuperAdmin,
        orgScope: user?.org_scope ?? null,
    };
}

export function usePermissions(): Permissions {
    const { user } = useAuth();
    return computePermissions(user);
}
