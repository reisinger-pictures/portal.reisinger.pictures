import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Brand Admin Sidebar', () => {
    let helper: E2ESessionHelper;
    let adminUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('B2B admin sees Organisationen in sidebar on default localhost', { tag: ['@smoke', '@feature:brand'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Organisationen');

        await expect(page.getByRole('heading', { name: 'Organisationen', exact: true })).toBeVisible({ timeout: 10000 });
    });
});
