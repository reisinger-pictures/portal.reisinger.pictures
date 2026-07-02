import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

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

        const card = page.locator('h2:has-text("Bankverbindung & Impressum")').locator('..');

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const holder = `E2E Inhaber ${uniqueSuffix}`;
        const iban = 'AT483200000012345864';
        const street = 'Musterstraße 1';
        const zip = '4020';
        const city = 'Linz';

        // SWR Hydrierungs-Delay abwarten
        await expect(card.getByPlaceholder(/Name des Inhabers/).first()).toBeVisible({ timeout: 10000 });

        await card.getByPlaceholder(/Name des Inhabers/).fill(holder);
        await card.getByPlaceholder(/Musterstraße/).fill(street);
        await card.getByPlaceholder(/4020/).fill(zip);
        await card.getByPlaceholder(/Linz/).fill(city);
        await card.getByPlaceholder(/IBAN|AT/i).first().fill(iban);

        await card.getByRole('button', { name: 'Bankdaten speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Bankdaten gespeichert');

        await page.reload();
        await expect(page.locator('h2:has-text("Bankverbindung & Impressum")')).toBeVisible();
        await expect(card.getByPlaceholder(/Name des Inhabers/)).toHaveValue(/E2E Inhaber [a-z0-9]+/);
        await expect(card.getByPlaceholder(/IBAN|AT/i).first()).toHaveValue(iban);
    });

    test('Invalid IBAN is rejected by validation', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const card = page.locator('h2:has-text("Bankverbindung & Impressum")').locator('..');

        // SWR Hydrierungs-Delay abwarten
        await expect(card.getByPlaceholder(/Name des Inhabers/).first()).toBeVisible({ timeout: 10000 });

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        await card.getByPlaceholder(/Name des Inhabers/).fill(`E2E Inhaber ${uniqueSuffix}`);
        await card.getByPlaceholder(/Musterstraße/).fill('Musterstraße 1');
        await card.getByPlaceholder(/4020/).fill('4020');
        await card.getByPlaceholder(/Linz/).fill('Linz');
        await card.getByPlaceholder(/IBAN|AT/i).first().fill('not-an-iban');

        await card.getByRole('button', { name: 'Bankdaten speichern' }).click();

        await expect(card.locator('.text-error')).toContainText(/IBAN/i);
        await expect(page.locator('.toast')).not.toContainText('Bankdaten gespeichert');
    });
});
