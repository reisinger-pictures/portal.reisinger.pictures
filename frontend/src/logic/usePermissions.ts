import { useAuth, User } from './useAuth';

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
}

export function computePermissions(user: User | null | undefined): Permissions {
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
    };
}

export function usePermissions(): Permissions {
    const { user } = useAuth();
    return computePermissions(user);
}
