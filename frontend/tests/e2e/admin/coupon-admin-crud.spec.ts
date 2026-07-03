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

        const { coupon: fixedCoupon } = await page.evaluate(async () => {
            const code = `FIXED-${Math.random().toString(36).substring(2, 8)}`;
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'fixed',
                    value: 15,
                    scope_type: 'global',
                    active: true,
                }),
            });
            return res.json();
        });

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(fixedCoupon.code, { timeout: 10000 });
    });

    test('Admin can create organisation-scoped coupon', async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const { coupon: orgCoupon } = await page.evaluate(async () => {
            const code = `ORG-${Math.random().toString(36).substring(2, 8)}`;
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'fixed',
                    value: 10,
                    scope_type: 'global',
                    active: true,
                }),
            });
            return res.json();
        });

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(orgCoupon.code, { timeout: 10000 });
    });

    test('Admin can delete a used coupon', async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const couponCode = `USED-${Math.random().toString(36).substring(2, 8)}`;
        const { coupon } = await page.evaluate(async (code) => {
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'fixed',
                    value: 5,
                    scope_type: 'global',
                    active: true,
                    used_count: 1,
                }),
            });
            return res.json();
        }, couponCode);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('table')).toContainText(coupon.code, { timeout: 15000 });

        const deleteBtn = page.locator('button[title="Löschen nicht möglich (bereits verwendet)"]').first();
        await expect(deleteBtn).toBeVisible();
        await expect(deleteBtn).toBeDisabled();
    });

    test('Admin can toggle coupon active/inactive', async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');

        const couponCode = `TOGGLE-${Math.random().toString(36).substring(2, 8)}`;
        const { coupon } = await page.evaluate(async (code) => {
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'percentage',
                    value: 10,
                    scope_type: 'global',
                    active: true,
                }),
            });
            return res.json();
        }, couponCode);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('table')).toContainText(coupon.code, { timeout: 15000 });
        await expect(page.locator('span.badge-success:has-text("Aktiv")').first()).toBeVisible({ timeout: 10000 });

        const toggleBtn = page.locator('button[title="Deaktivieren"]').first();
        await toggleBtn.click();
        await expect(page.locator('.toast')).toContainText('Gutscheincode deaktiviert');
        await expect(page.locator('span.badge-ghost:has-text("Inaktiv")').first()).toBeVisible();
    });
});
