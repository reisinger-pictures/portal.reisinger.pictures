import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import {
    adminCookie,
    createOrgFixture,
    setUserRoles,
    STUDIO_ADMIN,
    syncOrgMembers,
} from '../../helpers/AccessScopeFixture';

test.describe('Organization default role', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('a global admin configures the default and existing members keep their roles', { tag: ['@feature:org-default-role'] }, async ({ page, request }) => {
        const org = await createOrgFixture(request, helper, `E2E Default Role ${Math.random().toString(36).slice(2, 10)}`);
        const existingMember = await helper.createIsolatedUser('client');
        await syncOrgMembers(request, helper, org.id, [existingMember.id]);

        const adminToken = await adminCookie(helper);
        const rolesResponse = await request.get('/api/management/roles', {
            headers: { Accept: 'application/json', Cookie: adminToken },
        });
        if (!rolesResponse.ok()) throw new Error(`Role lookup failed: ${await rolesResponse.text()}`);
        const roles = await rolesResponse.json() as Array<{ id: string; name: string }>;
        const orgAdminRole = roles.find(role => role.name === 'org_admin');
        if (!orgAdminRole) throw new Error('org_admin role not found');

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(STUDIO_ADMIN.email, STUDIO_ADMIN.password);
        await sidebar.navigateTo('Organisationen');

        const main = page.getByRole('main');
        await main.locator('.card').filter({ hasText: org.name }).first().click();
        await expect(main.getByRole('heading', { name: org.name, exact: true })).toBeVisible();

        const defaultRoleSelect = main.getByRole('combobox', { name: 'Standardrolle für neue Mitglieder' });
        await defaultRoleSelect.selectOption(orgAdminRole.id);
        await expect(main.getByRole('alert')).toContainText('Nutzer in dieser Organisation verwalten');
        await main.getByRole('button', { name: 'Speichern', exact: true }).click();

        const detailResponse = await request.get(`/api/management/orgs/${org.id}`, {
            headers: { Accept: 'application/json', Cookie: adminToken },
        });
        if (!detailResponse.ok()) throw new Error(`Reading organization failed: ${await detailResponse.text()}`);
        const detail = await detailResponse.json() as { default_role_id: string | null };
        expect(detail.default_role_id).toBe(orgAdminRole.id);

        const usersResponse = await request.get('/api/management/users', {
            headers: { Accept: 'application/json', Cookie: adminToken },
        });
        if (!usersResponse.ok()) throw new Error(`Reading users failed: ${await usersResponse.text()}`);
        const users = await usersResponse.json() as {
            data: Array<{ id: string; roles: Array<{ id: string; name: string }> }>;
        };
        const unchangedMember = users.data.find(user => user.id === existingMember.id);
        expect(unchangedMember?.roles.map(role => role.name)).toEqual(['client']);
    });

    test('an organization admin cannot see or change the default role', { tag: ['@feature:org-default-role'] }, async ({ page, request }) => {
        const org = await createOrgFixture(request, helper, `E2E Org Admin Default Role ${Math.random().toString(36).slice(2, 10)}`);
        const orgAdmin = await helper.createIsolatedUser('client');
        await setUserRoles(request, helper, orgAdmin.id, ['client', 'org_admin']);
        await syncOrgMembers(request, helper, org.id, [orgAdmin.id]);

        const adminToken = await adminCookie(helper);
        const rolesResponse = await request.get('/api/management/roles', {
            headers: { Accept: 'application/json', Cookie: adminToken },
        });
        if (!rolesResponse.ok()) throw new Error(`Role lookup failed: ${await rolesResponse.text()}`);
        const roles = await rolesResponse.json() as Array<{ id: string; name: string }>;
        const powerUserRole = roles.find(role => role.name === 'power_user');
        if (!powerUserRole) throw new Error('power_user role not found');

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(orgAdmin.email, orgAdmin.password);
        await sidebar.navigateTo('Organisationen');

        const main = page.getByRole('main');
        await main.locator('.card').filter({ hasText: org.name }).first().click();
        await expect(main.getByRole('heading', { name: org.name, exact: true })).toBeVisible();
        await expect(main.getByRole('combobox', { name: 'Standardrolle für neue Mitglieder' })).toHaveCount(0);

        const orgAdminToken = await helper.loginAs(orgAdmin.email, orgAdmin.password);
        const rejectedUpdate = await request.put(`/api/management/orgs/${org.id}`, {
            data: {
                name: org.name,
                invoice_frequency: 'immediate',
                default_role_id: powerUserRole.id,
            },
            headers: { Accept: 'application/json', Cookie: orgAdminToken },
        });
        expect(rejectedUpdate.status()).toBe(403);

        const detailResponse = await request.get(`/api/management/orgs/${org.id}`, {
            headers: { Accept: 'application/json', Cookie: adminToken },
        });
        if (!detailResponse.ok()) throw new Error(`Reading organization failed: ${await detailResponse.text()}`);
        const detail = await detailResponse.json() as { default_role_id: string | null };
        expect(detail.default_role_id).toBeNull();
    });

    test('an organization admin can assign and remove the org_admin role in their organization', { tag: ['@feature:org-default-role'] }, async ({ page, request }) => {
        const org = await createOrgFixture(request, helper, `E2E Org Admin Role ${Math.random().toString(36).slice(2, 10)}`);
        const orgAdmin = await helper.createIsolatedUser('client');
        const member = await helper.createIsolatedUser('client');
        await setUserRoles(request, helper, orgAdmin.id, ['client', 'org_admin']);
        await syncOrgMembers(request, helper, org.id, [orgAdmin.id, member.id]);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(orgAdmin.email, orgAdmin.password);
        // A pure org admin (no admin role) sees the `/users` entry as "Mein Team";
        // "Benutzer & Rechte" is the admin-only label for the same route.
        await sidebar.navigateTo('Mein Team');

        const main = page.getByRole('main');
        const search = main.getByPlaceholder('Nutzer suchen (Name oder E-Mail)...');
        await search.fill(member.email);
        const memberRow = main.getByRole('row').filter({ hasText: member.email });
        await memberRow.getByRole('button', { name: 'Bearbeiten' }).click();

        let dialog = main.getByRole('dialog', { name: 'E2E client bearbeiten' });
        const orgAdminRole = dialog.getByRole('checkbox', { name: 'org_admin' });
        await expect(orgAdminRole).toBeVisible();
        await expect(orgAdminRole).not.toBeChecked();
        await orgAdminRole.check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();

        await memberRow.getByRole('button', { name: 'Bearbeiten' }).click();
        dialog = main.getByRole('dialog', { name: 'E2E client bearbeiten' });
        const selectedOrgAdminRole = dialog.getByRole('checkbox', { name: 'org_admin' });
        await expect(selectedOrgAdminRole).toBeChecked();
        await selectedOrgAdminRole.uncheck();
        await dialog.getByRole('button', { name: 'Speichern' }).click();

        const orgAdminCookie = await helper.loginAs(orgAdmin.email, orgAdmin.password);
        const usersResponse = await request.get('/api/management/users', {
            headers: { Accept: 'application/json', Cookie: orgAdminCookie },
        });
        if (!usersResponse.ok()) throw new Error(`Reading organization users failed: ${await usersResponse.text()}`);
        const users = await usersResponse.json() as {
            data: Array<{ id: string; roles: Array<{ name: string }> }>;
        };
        const updatedMember = users.data.find(user => user.id === member.id);
        expect(updatedMember?.roles.map(role => role.name)).toEqual(['client']);
    });
});
