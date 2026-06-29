import { describe, it, expect } from 'vitest';
import { computePermissions } from '../usePermissions';
import type { User } from '../useAuth';

function user(overrides: Partial<User> = {}): User {
    return {
        id: 'u1',
        name: 'Test',
        email: 'test@example.com',
        is_super_admin: false,
        is_admin: false,
        is_photographer: false,
        is_pending: false,
        can_edit_metadata: false,
        roles: [],
        ...overrides,
    };
}

describe('computePermissions', () => {
    it('returns all false for null/undefined user', () => {
        const p = computePermissions(null);
        expect(p.isStaff).toBe(false);
        expect(p.isSuperAdmin).toBe(false);
        expect(p.isAdmin).toBe(false);
        expect(p.isPhotographer).toBe(false);
        expect(p.isCustomerManager).toBe(false);
        expect(p.canEditMetadata).toBe(false);
        expect(p.isPowerUser).toBe(false);
        expect(p.canAccessB2BFeatures).toBe(false);
        expect(p.showTenantsSection).toBe(false);
        expect(p.showCRM).toBe(false);
        expect(p.showInvoicing).toBe(false);
        expect(p.showPayouts).toBe(false);
    });

    it('returns all false for undefined user', () => {
        const p = computePermissions(undefined);
        expect(p.isStaff).toBe(false);
        expect(p.canAccessB2BFeatures).toBe(false);
        expect(p.showPayouts).toBe(false);
    });

    describe('isStaff', () => {
        it('is true for super admin', () => {
            expect(computePermissions(user({ is_super_admin: true })).isStaff).toBe(true);
        });

        it('is true for admin', () => {
            expect(computePermissions(user({ is_admin: true })).isStaff).toBe(true);
        });

        it('is true for photographer', () => {
            expect(computePermissions(user({ is_photographer: true })).isStaff).toBe(true);
        });

        it('is false for regular client', () => {
            expect(computePermissions(user({ roles: ['client'] })).isStaff).toBe(false);
        });

        it('is false for pending user', () => {
            expect(computePermissions(user({ is_pending: true })).isStaff).toBe(false);
        });
    });

    it('canAccessB2BFeatures equals isStaff', () => {
        const staffUser = user({ is_admin: true });
        expect(computePermissions(staffUser).canAccessB2BFeatures).toBe(true);

        const clientUser = user({ roles: ['client'] });
        expect(computePermissions(clientUser).canAccessB2BFeatures).toBe(false);
    });

    it('showTenantsSection / showCRM / showInvoicing equal canAccessB2BFeatures', () => {
        const p = computePermissions(user({ is_admin: true }));
        expect(p.showTenantsSection).toBe(true);
        expect(p.showCRM).toBe(true);
        expect(p.showInvoicing).toBe(true);
    });

    it('showPayouts is true only for super admin', () => {
        expect(computePermissions(user({ is_super_admin: true })).showPayouts).toBe(true);
        expect(computePermissions(user({ is_admin: true })).showPayouts).toBe(false);
        expect(computePermissions(user({ is_photographer: true })).showPayouts).toBe(false);
        expect(computePermissions(user({})).showPayouts).toBe(false);
    });

    describe('individual role booleans', () => {
        it('isSuperAdmin', () => {
            expect(computePermissions(user({ is_super_admin: true })).isSuperAdmin).toBe(true);
            expect(computePermissions(user({ is_admin: true })).isSuperAdmin).toBe(false);
        });

        it('isAdmin', () => {
            expect(computePermissions(user({ is_admin: true })).isAdmin).toBe(true);
            expect(computePermissions(user({ is_photographer: true })).isAdmin).toBe(false);
        });

        it('isPhotographer', () => {
            expect(computePermissions(user({ is_photographer: true })).isPhotographer).toBe(true);
            expect(computePermissions(user({ is_admin: true })).isPhotographer).toBe(false);
        });

        it('isCustomerManager', () => {
            expect(computePermissions(user({ is_customer_manager: true })).isCustomerManager).toBe(true);
            expect(computePermissions(user({})).isCustomerManager).toBe(false);
        });

        it('canEditMetadata', () => {
            expect(computePermissions(user({ can_edit_metadata: true })).canEditMetadata).toBe(true);
            expect(computePermissions(user({})).canEditMetadata).toBe(false);
        });

        it('isPowerUser', () => {
            expect(computePermissions(user({ is_power_user: true })).isPowerUser).toBe(true);
            expect(computePermissions(user({})).isPowerUser).toBe(false);
        });
    });

    describe('magic link / guest scenarios', () => {
        it('transient user without roles gets no permissions', () => {
            const transientUser = user({
                is_super_admin: false,
                is_admin: false,
                is_photographer: false,
                roles: [],
                can_edit_metadata: false,
            });
            const p = computePermissions(transientUser);
            expect(p.isStaff).toBe(false);
            expect(p.canAccessB2BFeatures).toBe(false);
            expect(p.showPayouts).toBe(false);
        });

        it('transient user with can_edit_metadata and transient_meta_galleries can edit metadata', () => {
            const transientWithMetaEdit = user({
                can_edit_metadata: true,
                transient_meta_galleries: ['g1'],
            });
            expect(computePermissions(transientWithMetaEdit).canEditMetadata).toBe(true);
        });
    });
});
