import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ToastHelper } from '../helpers/ToastHelper';

// E2E coverage for the `photo_package` (Foto-Paket) coupon type.
// The coupon form has no separate "name" field — the unique identifier is `code`.
// The list renders photo_package value as "<N> Fotos / <Y> €" (formatMoney → "40.00 €").
const PHOTO_PACKAGE_DISPLAY = /10 Fotos \/ 40[.,]00\s*€/;

test.describe('Coupon photo_package (Foto-Paket)', () => {
    let helper: E2ESessionHelper;
    let superAdmin: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        // super_admin is cross-brand → no brand assignment
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin creates a photo_package coupon via the UI form', { tag: ['@feature:coupon'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(superAdmin.email, superAdmin.password, 'http://localhost:4321/');

        const couponCode = `FOTO-${Math.random().toString(36).substring(2, 8)}`.toUpperCase();

        // Navigate via Sidebar (no page.goto for SPA nav)
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        // Open the creation drawer
        await page.getByRole('button', { name: 'Neuen Gutscheincode anlegen' }).click();
        const drawer = page.locator('.modal-open');
        await expect(drawer).toBeVisible({ timeout: 5000 });

        // Fill the form
        await drawer.getByPlaceholder('z.B. SOMMER2026').fill(couponCode);
        // First <select> is the "Typ" field
        await drawer.locator('select').first().selectOption('photo_package');
        await drawer.getByPlaceholder('z.B. 10').fill('10'); // Anzahl Fotos (N)
        await drawer.getByPlaceholder('z.B. 40').fill('40.00'); // Festpreis in € (Y)

        // Submit and expect success toast
        const toast = new ToastHelper(page);
        await drawer.getByRole('button', { name: 'Speichern' }).click();
        await toast.expectToast('Gutscheincode angelegt');

        // Verify the coupon appears in the list with the correct photo_package display
        const row = page.locator('main tr').filter({ hasText: couponCode }).first();
        await expect(row).toBeVisible({ timeout: 10000 });
        await expect(row).toContainText(PHOTO_PACKAGE_DISPLAY);
    });

    test('photo_package coupon displays "N Fotos / Y €" in the management list', { tag: ['@feature:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(superAdmin.email, superAdmin.password, 'http://localhost:4321/');
        const cookie = await helper.loginAs(superAdmin.email, superAdmin.password, { brand: 'rp' });

        const couponCode = `FOTO-${Math.random().toString(36).substring(2, 8)}`.toUpperCase();
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Cookie': cookie,
            'Referer': 'http://localhost:4321/',
        };
        const createRes = await request.post('/api/management/coupons', {
            data: {
                code: couponCode,
                type: 'photo_package',
                package_quantity: 10,
                package_price_cents: 40, // Euro → 4000 cents via controller mapping
                scope_type: 'global',
                active: true,
            },
            headers,
        });
        const createResJson = await createRes.json();
        if (createResJson?.coupon?.id) helper.trackCoupon(createResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        const row = page.locator('main tr').filter({ hasText: couponCode }).first();
        await expect(row).toBeVisible({ timeout: 10000 });
        await expect(row).toContainText(PHOTO_PACKAGE_DISPLAY);
    });
});
