import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';

test.describe('SRP Brand Dashboard (G12)', () => {
    let helper: E2ESessionHelper;
    let adminUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin', { brand: 'srp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test.skip(({ browserName }) => browserName !== 'chromium', 'Desktop only');

    test('SRP admin sidebar has no B2B admin items', async ({ page }) => {
        test.setTimeout(30000);
        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password, 'http://buy.localhost:4321/');

        const sidebar = page.locator('aside');
        await expect(sidebar.getByText('Shop-Bestellungen')).toHaveCount(0);
        await expect(sidebar.getByText('Payouts & Abrechnung')).toHaveCount(0);
        await expect(sidebar.getByText('Büro & Dokumente')).toHaveCount(0);
        await expect(sidebar.getByText('Dashboard')).toBeVisible();
        await expect(sidebar.getByText('Gutscheincode')).toBeVisible();
        await expect(sidebar.getByText('Organisationen')).toHaveCount(0);
    });

    test('SRP admin is redirected from B2B routes', async ({ page }) => {
        test.setTimeout(30000);
        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password, 'http://buy.localhost:4321/');

        await page.goto('http://buy.localhost:4321/tenants');
        await expect(page.getByRole('heading', { name: /Organisationen/ })).toBeVisible({ timeout: 15000 });
    });

    test('SRP client sidebar has no admin items', async ({ page }) => {
        test.setTimeout(30000);
        const clientUser = await helper.createIsolatedUser('power_user', { brand: 'srp' });
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password, 'http://buy.localhost:4321/');

        const sidebar = page.locator('aside');
        await expect(sidebar.getByText('Shop-Bestellungen')).toHaveCount(0);
        await expect(sidebar.getByText('Payouts & Abrechnung')).toHaveCount(0);
        await expect(sidebar.getByText('Büro & Dokumente')).toHaveCount(0);
        await expect(sidebar.getByText('Organisationen')).toHaveCount(0);
    });
});
