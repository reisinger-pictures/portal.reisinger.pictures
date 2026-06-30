import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Brand Tenant Isolation', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test.describe('B2B brand (reisinger.pictures) - default localhost', () => {
        let adminUser: { email: string; password: string; id: string };

        test.beforeEach(async ({ request }) => {
            helper = new E2ESessionHelper(request);
            adminUser = await helper.createIsolatedUser('admin');
        });

        test('Admin sees B2B Mandanten section in sidebar', async ({ page }) => {
            const auth = new AuthHelper(page);
            const sidebar = new SidebarHelper(page);

            await auth.login(adminUser.email, adminUser.password);
            await sidebar.navigateTo('Mandanten (B2B)');

            await expect(page.getByRole('heading', { name: /Organisationen/ })).toBeVisible({ timeout: 10000 });
        });
    });

    test.describe('ATR brand (all-the.rest) - mocked hostname', () => {
        let adminUser: { email: string; password: string; id: string };

        test.beforeEach(async ({ request }) => {
            helper = new E2ESessionHelper(request);
            adminUser = await helper.createIsolatedUser('admin');
        });

        test('Admin on ATR brand can still access B2B route /tenants', async ({ page }) => {
            await page.addInitScript(() => {
                Object.defineProperty(window.location, 'hostname', {
                    get: () => 'all-the.rest',
                    configurable: true,
                });
            });

            const auth = new AuthHelper(page);
            await auth.login(adminUser.email, adminUser.password);

            await page.goto('/tenants');
            await expect(page.getByRole('heading', { name: /Organisationen/ })).toBeVisible({ timeout: 10000 });
        });

        test('Admin on ATR brand sees B2B Mandanten link in sidebar', async ({ page }) => {
            await page.addInitScript(() => {
                Object.defineProperty(window.location, 'hostname', {
                    get: () => 'all-the.rest',
                    configurable: true,
                });
            });

            const auth = new AuthHelper(page);
            await auth.login(adminUser.email, adminUser.password);

            const sidebar = page.locator('aside');
            const mandantenLink = sidebar.getByText('Mandanten (B2B)');
            await expect(mandantenLink).toBeVisible({ timeout: 10000 });
        });

        test('Client on ATR brand is redirected away from /tenants', async ({ page }) => {
            await page.addInitScript(() => {
                Object.defineProperty(window.location, 'hostname', {
                    get: () => 'all-the.rest',
                    configurable: true,
                });
            });

            const clientUser = await helper.createIsolatedUser('client');
            const auth = new AuthHelper(page);
            await auth.login(clientUser.email, clientUser.password);

            await page.goto('/tenants');

            await expect(page.getByRole('heading', { name: /^Willkommen zurück/ })).toBeVisible({ timeout: 15000 });
        });

        test('Client on ATR brand does not see B2B Mandanten in sidebar', async ({ page }) => {
            await page.addInitScript(() => {
                Object.defineProperty(window.location, 'hostname', {
                    get: () => 'all-the.rest',
                    configurable: true,
                });
            });

            const clientUser = await helper.createIsolatedUser('client');
            const auth = new AuthHelper(page);
            await auth.login(clientUser.email, clientUser.password);

            const sidebar = page.locator('aside');
            await expect(sidebar.getByText('Mandanten (B2B)')).toHaveCount(0);
        });
    });
});
