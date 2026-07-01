import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Coupon Admin CRUD', () => {
    let helper: E2ESessionHelper;
    let srpAdmin: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        srpAdmin = await helper.createIsolatedUser('admin', { brand: 'srp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can create a fixed global coupon', async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1:has-text("Coupons")')).toBeVisible();

        await page.getByRole('button', { name: 'Neuen Coupon anlegen' }).click();
        await page.fill('input[placeholder="z.B. SOMMER2026"]', 'FIXEDGLOBAL');
        await page.fill('input[type="number"]', '15');

        const typeSelect = page.locator('select').first();
        await typeSelect.selectOption('fixed');
        const scopeSelect = page.locator('select').nth(1);
        await scopeSelect.selectOption('global');

        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.locator('.toast')).toContainText('Coupon angelegt');
        await expect(page.locator('table')).toContainText('FIXEDGLOBAL');
    });

    test('Admin can create organisation-scoped coupon', async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1:has-text("Coupons")')).toBeVisible();

        await page.getByRole('button', { name: 'Neuen Coupon anlegen' }).click();
        await page.fill('input[placeholder="z.B. SOMMER2026"]', 'ORGTEN');
        await page.fill('input[type="number"]', '10');

        const typeSelect = page.locator('select').first();
        await typeSelect.selectOption('fixed');
        const scopeSelect = page.locator('select').nth(1);
        await scopeSelect.selectOption('organisation');

        await page.fill('input[placeholder="Galerie-ID"]', 'org-001');

        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.locator('.toast')).toContainText('Coupon angelegt');
        await expect(page.locator('table')).toContainText('ORGTEN');
    });

    test('Admin can delete a used coupon', async ({ request, page }) => {
        const adminLogin = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' },
        });
        expect(adminLogin.ok()).toBeTruthy();
        const cookies = adminLogin.headers()['set-cookie']!;
        const adminToken = cookies.includes('rp_jwt=') ? cookies.match(/rp_jwt=([^;]+)/)![0] : cookies;

        const couponRes = await request.post('/api/management/coupons', {
            data: {
                code: `USED-${Math.random().toString(36).substring(2, 8)}`,
                type: 'fixed',
                value: 5,
                scope_type: 'global',
                active: true,
                used_count: 1,
            },
            headers: { 'Accept': 'application/json', 'Cookie': adminToken },
        });
        expect(couponRes.ok()).toBeTruthy();
        const coupon = await couponRes.json();

        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('table')).toContainText(coupon.code);

        const deleteBtn = page.locator('button[title="Löschen nicht möglich (bereits verwendet)"]');
        await expect(deleteBtn).toBeVisible();
        await expect(deleteBtn).toBeDisabled();
    });

    test('Admin can toggle coupon active/inactive', async ({ request, page }) => {
        const adminLogin = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' },
        });
        expect(adminLogin.ok()).toBeTruthy();
        const cookies = adminLogin.headers()['set-cookie']!;
        const adminToken = cookies.includes('rp_jwt=') ? cookies.match(/rp_jwt=([^;]+)/)![0] : cookies;

        const couponRes = await request.post('/api/management/coupons', {
            data: {
                code: `TOGGLE-${Math.random().toString(36).substring(2, 8)}`,
                type: 'percentage',
                value: 10,
                scope_type: 'global',
                active: true,
            },
            headers: { 'Accept': 'application/json', 'Cookie': adminToken },
        });
        expect(couponRes.ok()).toBeTruthy();

        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('span.badge-success:has-text("Aktiv")')).toBeVisible();

        const toggleBtn = page.locator('button[title="Deaktivieren"]');
        await toggleBtn.click();
        await expect(page.locator('.toast')).toContainText('Coupon deaktiviert');
        await expect(page.locator('span.badge-ghost:has-text("Inaktiv")')).toBeVisible();
    });
});
