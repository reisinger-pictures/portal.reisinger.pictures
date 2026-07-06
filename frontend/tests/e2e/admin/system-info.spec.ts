import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('System Info Page (G10)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('System info page displays system information', { tags: ['@feature:admin:system'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Einstellungen');

        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const systemInfoSection = page.locator('main').locator('text=System Info');
        await expect(systemInfoSection.first()).toBeVisible({ timeout: 10000 });

        const systemInfoBox = systemInfoSection.first().locator('..').locator('..');

        await expect(systemInfoBox.locator('text=Datenbank Version:')).toBeVisible();
        await expect(systemInfoBox.locator('text=React Build:')).toBeVisible();
        await expect(systemInfoBox.locator('text=Laravel Update:')).toBeVisible();
        await expect(systemInfoBox.locator('text=Backend:')).toBeVisible();
    });
});
