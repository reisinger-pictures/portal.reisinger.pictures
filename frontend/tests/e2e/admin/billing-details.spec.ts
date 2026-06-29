import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

/**
 * B-02 regression guard: the bank details form must save via an explicit "Bankdaten speichern"
 * button (no per-keystroke PUT / SWR race) and persist the values across a reload.
 * The endpoint is super_admin-only; the form is read-only for everyone else.
 */
test.describe('Billing Details Save (Bankdaten)', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin can save bank details and they persist after reload', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        // Scope to the Bankverbindung card via its heading landmark.
        const card = page.locator('h2:has-text("Bankverbindung & Impressum")').locator('..');

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const holder = `E2E Inhaber ${uniqueSuffix}`;
        const iban = 'AT483200000012345864';

        await card.getByPlaceholder(/Name des Inhabers/).fill(holder);
        await card.getByPlaceholder(/IBAN|AT/i).first().fill(iban);

        await card.getByRole('button', { name: 'Bankdaten speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Bankdaten gespeichert');

        // Reload → values must come back from the server (no race overwrite).
        await page.reload();
        await expect(page.locator('h2:has-text("Bankverbindung & Impressum")')).toBeVisible();
        await expect(card.getByPlaceholder(/Name des Inhabers/)).toHaveValue(holder);
        await expect(card.getByPlaceholder(/IBAN|AT/i).first()).toHaveValue(iban);
    });

    test('Invalid IBAN is rejected by validation', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const card = page.locator('h2:has-text("Bankverbindung & Impressum")').locator('..');
        await card.getByPlaceholder(/IBAN|AT/i).first().fill('not-an-iban');

        await card.getByRole('button', { name: 'Bankdaten speichern' }).click();

        // zod validation error must surface; no success toast.
        await expect(card.locator('.text-error')).toContainText(/IBAN/i);
        await expect(page.locator('.toast')).toHaveCount(0);
    });
});
