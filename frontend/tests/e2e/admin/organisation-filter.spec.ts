import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('G6: Gallery Tree Organisation Filter', () => {
    let helper: E2ESessionHelper;
    let adminUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Organisation filter hides groups assigned to other tenants', { tags: ['@feature:admin:tenant'] }, async ({ page, request }) => {
        const adminToken = helper.getAdminToken();
        const headers = { 'Accept': 'application/json', 'Cookie': adminToken };

        const tenant1Name = `E2E Tenant 1 ${Math.random().toString(36).substring(2, 10)}`;
        const tenant1Res = await request.post('/api/management/tenants', {
            data: { name: tenant1Name, invoice_frequency: 'immediate' },
            headers
        });
        const tenant1 = await tenant1Res.json();
        const tenant1Id = tenant1.tenant?.id;
        if (!tenant1Id) throw new Error('Tenant 1 ID missing');
        helper.trackTenant(tenant1Id);

        const tenant2Name = `E2E Tenant 2 ${Math.random().toString(36).substring(2, 10)}`;
        const tenant2Res = await request.post('/api/management/tenants', {
            data: { name: tenant2Name, invoice_frequency: 'immediate' },
            headers
        });
        const tenant2 = await tenant2Res.json();
        const tenant2Id = tenant2.tenant?.id;
        if (!tenant2Id) throw new Error('Tenant 2 ID missing');
        helper.trackTenant(tenant2Id);

        const group1Name = `E2E Group 1 ${Math.random().toString(36).substring(2, 10)}`;
        const group1Res = await request.post('/api/management/gallery-groups', {
            data: { name: group1Name, tenant_id: tenant1Id },
            headers
        });
        const group1Data = await group1Res.json();
        if (group1Data?.group?.id) helper.trackGroup(group1Data.group.id);
        if (!group1Res.ok()) throw new Error(`Group 1 creation failed: ${await group1Res.text()}`);

        const group2Name = `E2E Group 2 ${Math.random().toString(36).substring(2, 10)}`;
        const group2Res = await request.post('/api/management/gallery-groups', {
            data: { name: group2Name, tenant_id: tenant2Id },
            headers
        });
        const group2Data = await group2Res.json();
        if (group2Data?.group?.id) helper.trackGroup(group2Data.group.id);
        if (!group2Res.ok()) throw new Error(`Group 2 creation failed: ${await group2Res.text()}`);

        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Galerien & Ordner');

        const main = page.locator('main');

        await expect(main.locator('summary').filter({ hasText: group1Name })).toBeVisible({ timeout: 10000 });
        await expect(main.locator('summary').filter({ hasText: group2Name })).toBeVisible({ timeout: 10000 });

        const filterSelect = main.locator('select').first();
        await expect(filterSelect.locator(`option[value="${tenant1Id}"]`)).toBeAttached({ timeout: 10000 });
        await filterSelect.selectOption(tenant1Id);

        await expect(main.locator('summary').filter({ hasText: group1Name })).toBeVisible({ timeout: 10000 });
        await expect(main.locator('summary').filter({ hasText: group2Name })).toHaveCount(0);
    });
});
