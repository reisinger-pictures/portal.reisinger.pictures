import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('B7: Doppelte Sidebar Regression', () => {
    let helper: E2ESessionHelper;
    let admin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        admin = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Sidebar wird nur einmal gerendert', { tag: ['@feature:admin:ui'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(admin.email, admin.password);
        await sidebar.navigateTo('Auswertungen');

        await expect(page.locator('aside')).toHaveCount(1);
    });
});
