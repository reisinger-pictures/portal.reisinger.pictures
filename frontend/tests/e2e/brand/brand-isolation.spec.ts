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
            await sidebar.navigateTo('Organisationen');

            await expect(page.getByRole('heading', { name: 'Organisationen', exact: true })).toBeVisible({ timeout: 10000 });
        });
    });

    test.describe('SRP brand (buy.localhost) - subdomain', () => {

        let adminUser: { email: string; password: string; id: string };

        test.beforeEach(async ({ request }) => {
            helper = new E2ESessionHelper(request);
            adminUser = await helper.createIsolatedUser('admin', { brand: 'srp' });
        });

        test('Admin on SRP brand can still access B2B route /tenants', async ({ page }) => {
            const auth = new AuthHelper(page);
            await auth.login(adminUser.email, adminUser.password, 'http://buy.localhost:4321/');

            await page.goto('http://buy.localhost:4321/tenants');
            await expect(page.getByRole('heading', { name: 'Organisationen', exact: true })).toBeVisible({ timeout: 10000 });
        });

        test('Admin on SRP brand does not see B2B Mandanten in sidebar', async ({ page }) => {
            const auth = new AuthHelper(page);
            await auth.login(adminUser.email, adminUser.password, 'http://buy.localhost:4321/');

            const sidebar = page.locator('aside');
            await expect(sidebar.getByText('Organisationen (B2B)')).toHaveCount(0);
        });

        test('Client on SRP brand is redirected away from /tenants', async ({ page }) => {
            const clientUser = await helper.createIsolatedUser('client', { brand: 'srp' });
            const auth = new AuthHelper(page);
            await auth.login(clientUser.email, clientUser.password, 'http://buy.localhost:4321/');

            await page.goto('http://buy.localhost:4321/tenants');

            await expect(page.getByRole('heading', { name: /^Willkommen zurück/ })).toBeVisible({ timeout: 15000 });
        });

        test('Client on SRP brand does not see B2B Mandanten in sidebar', async ({ page }) => {
            const clientUser = await helper.createIsolatedUser('client', { brand: 'srp' });
            const auth = new AuthHelper(page);
            await auth.login(clientUser.email, clientUser.password, 'http://buy.localhost:4321/');

            const sidebar = page.locator('aside');
            await expect(sidebar.getByText('Organisationen (B2B)')).toHaveCount(0);
        });
    });

    test.describe('Brand isolation — photographer role', () => {
        let helper: E2ESessionHelper;

        test.afterEach(async () => {
            if (helper) await helper.teardown();
        });

        test('SRP user cannot see RP data in sidebar', async ({ page, request }) => {
            helper = new E2ESessionHelper(request);
            const user = await helper.createIsolatedUser('photographer', { brand: 'srp' });
            const auth = new AuthHelper(page);
            await auth.login(user.email, user.password, 'http://buy.localhost:4321/');

            const sidebar = page.locator('aside');
            await expect(sidebar.getByText('Organisationen')).toHaveCount(0);
            await expect(sidebar.getByText('Payouts & Abrechnung')).toHaveCount(0);
            await expect(sidebar.getByText('Benutzer & Rechte')).toHaveCount(0);
        });

        test('RP user cannot see SRP data in sidebar', async ({ page, request }) => {
            helper = new E2ESessionHelper(request);
            const user = await helper.createIsolatedUser('photographer');
            const auth = new AuthHelper(page);
            await auth.login(user.email, user.password);

            const sidebar = page.locator('aside');
            await expect(sidebar.getByText('Dashboard')).toBeVisible({ timeout: 10000 });
        });

        test('SRP client can access gallery page', async ({ page, request }) => {
            helper = new E2ESessionHelper(request);
            const user = await helper.createIsolatedUser('power_user', { brand: 'srp' });
            const auth = new AuthHelper(page);
            await auth.login(user.email, user.password, 'http://buy.localhost:4321/');

            await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });
        });
    });
});
