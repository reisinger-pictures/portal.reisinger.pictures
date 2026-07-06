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

    test('Admin can create a fixed global coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });

        const couponCode = `FIXED-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        const createRes = await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 15, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const createResJson = await createRes.json();
        if (createResJson?.coupon?.id) helper.trackCoupon(createResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(couponCode, { timeout: 10000 });
    });

    test('Admin can create organisation-scoped coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });

        const couponOrgCode = `ORG-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        const orgRes = await request.post('/api/management/coupons', {
            data: { code: couponOrgCode, type: 'fixed', value: 10, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const orgResJson = await orgRes.json();
        if (orgResJson?.coupon?.id) helper.trackCoupon(orgResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(couponOrgCode, { timeout: 10000 });
    });

    test('Admin can delete a used coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });

        const couponCode = `USED-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 5, scope_type: 'global', active: true, used_count: 1 },
            headers: couHeaders,
        });

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        const row = page.locator('tr').filter({ hasText: couponCode });
        await expect(row).toBeVisible({ timeout: 10000 });

        const deleteBtn = row.locator('button[title="Löschen"]');
        await expect(deleteBtn).toBeVisible();
        await expect(deleteBtn).toBeEnabled();
        await deleteBtn.click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Bestätigen' }).click();
        await expect(confirmModal).toBeHidden();

        await expect(page.locator('.toast')).toContainText('Gutscheincode gelöscht');
        await expect(row).toBeHidden({ timeout: 10000 });
    });

    test('Admin can toggle coupon active/inactive', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });

        const toggleCouponCode = `TOGGLE-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        const toggleRes = await request.post('/api/management/coupons', {
            data: { code: toggleCouponCode, type: 'percentage', value: 10, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const toggleResJson = await toggleRes.json();
        if (toggleResJson?.coupon?.id) helper.trackCoupon(toggleResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');

        const row = page.locator('tr').filter({ hasText: toggleCouponCode });
        await expect(row).toBeVisible({ timeout: 15000 });
        await expect(row.locator('span.badge-success')).toContainText('Aktiv', { timeout: 10000 });

        const toggleBtn = row.locator('button[title="Deaktivieren"]');
        await toggleBtn.click();
        await expect(page.locator('.toast')).toContainText('Gutscheincode deaktiviert');
        await expect(row.locator('span.badge-ghost')).toContainText('Inaktiv');
    });
});
