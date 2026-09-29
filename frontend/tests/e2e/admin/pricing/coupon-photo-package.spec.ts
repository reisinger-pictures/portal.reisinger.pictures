import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ToastHelper } from '../../helpers/ToastHelper';

// E2E coverage for the `photo_package` (Foto-Paket) coupon type.
// The coupon form has no separate "name" field — the unique identifier is `code`.
// The list renders photo_package value as "<N> Fotos / <Y> €" (formatMoney →
// "40,00 €" in de-DE, NBSP before the symbol). The separator is pinned to the
// comma: a `[.,]` tolerance here is what let the display drift to "40.00 €"
// unnoticed while this test kept passing.
//
// The amount the admin TYPES is euros ("Festpreis in € (Y)"), the amount the API
// and the column hold is cents (owner decision 2026-09-28) — the form is the
// boundary that converts. This spec therefore types 40.00 and asserts 40,00 €,
// which is the same pairing as before the change: the fixture literal is
// unchanged, and that is the point. The conversion is proven at the API/column
// level in the backend feature test, where the wire value can be asserted
// directly; typing 4000 here would only move the mistake to the UI.
const PHOTO_PACKAGE_DISPLAY = /10 Fotos \/ 40,00\s*€/;

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
        const createResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/management/coupons'
                && response.request().method() === 'POST'
                && response.ok();
        }, { timeout: 15000 });
        await drawer.getByRole('button', { name: 'Speichern' }).click();
        await toast.expectToast('Gutscheincode angelegt');

        // The wire value is cents (owner decision 2026-09-28) and the form is
        // the only place that converts 40,00 € into it. Asserting the request
        // body is what makes this a round-trip test: a backend that scaled
        // again would store 400000 here and render "4.000,00 €" below, and a
        // frontend that stopped converting would send 40 and render "0,40 €".
        // Both are silent without this assertion.
        const createBody = JSON.parse(await (await createResponsePromise).text()) as {
            coupon?: { id?: string | number; package_price_cents?: number };
        };
        const createdId = createBody.coupon?.id;
        expect(createBody.coupon?.package_price_cents, 'wire value is cents').toBe(4000);
        if (createdId === undefined || createdId === null) {
            throw new Error('Photo-package coupon was created without an id');
        }
        helper.trackCoupon(String(createdId));

        // Verify the coupon appears in the list with the correct photo_package display
        const row = page.locator('main tr').filter({ hasText: couponCode }).first();
        await expect(row).toBeVisible({ timeout: 10000 });
        await expect(row).toContainText(PHOTO_PACKAGE_DISPLAY);
    });

    /**
     * The read-back half of the round trip: what the form shows when an
     * existing coupon is re-opened is what was typed. Before the change the
     * backend multiplied and the form divided, which is why a 40,00 € package
     * displayed correctly; if only one of the two sides moved, this would show
     * 400,00 € (or 0,40 €) and the created coupon above would still pass.
     */
    test('Re-opening a photo_package coupon shows the price that was entered', { tag: ['@feature:coupon'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(superAdmin.email, superAdmin.password, 'http://localhost:4321/');

        const couponCode = `FOTO-EDIT-${Math.random().toString(36).substring(2, 8)}`.toUpperCase();

        // Created BEFORE the list is opened: the list is an SWR read that is not
        // subscribed to writes made through the API, so a row created after the
        // page loaded would never appear.
        const created = await helper.createCoupon({
            code: couponCode,
            type: 'photo_package',
            value: 0,
            package_quantity: 10,
            package_price_cents: 4000,
            scope_type: 'global',
            active: true,
        });
        if (!created.coupon?.id) throw new Error('Photo-package coupon was created without an id');
        helper.trackCoupon(String(created.coupon.id));

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        const row = page.locator('main tr').filter({ hasText: couponCode }).first();
        await expect(row).toBeVisible({ timeout: 10000 });
        await expect(row).toContainText(PHOTO_PACKAGE_DISPLAY);

        await row.locator('button[title="Bearbeiten"]').click();
        const drawer = page.locator('.modal-open');
        await expect(drawer).toBeVisible({ timeout: 5000 });
        // 4000 cents must read back as "40" in a euro field — not 4000, and
        // not 0.40.
        await expect(drawer.getByPlaceholder('z.B. 40')).toHaveValue('40');
    });
});
