import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ToastHelper } from '../helpers/ToastHelper';

test.describe('Brand Settings (Markeneinstellungen)', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin kann eine Markeneinstellung bearbeiten und der Wert bleibt nach Reload erhalten', { tag: ['@feature:admin:brand-settings'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const card = page.getByTestId('brand-settings-card');
        await expect(card).toBeVisible();

        const brand = card.locator('details').first();
        await brand.locator('summary').click();

        const uniqueEmail = `e2e-brand-${Math.random().toString(36).substring(2, 8)}@example.com`;
        const emailInput = brand.getByPlaceholder(/buchhaltung@reisinger/i);

        // SWR-Hydrierungs-Delay abwarten
        await expect(emailInput).toBeVisible({ timeout: 10000 });

        await emailInput.fill(uniqueEmail);
        await brand.getByRole('button', { name: 'Speichern' }).click();

        await new ToastHelper(page).expectToast('Markeneinstellungen gespeichert');

        await page.reload();
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const cardAfter = page.getByTestId('brand-settings-card');
        await expect(cardAfter).toBeVisible();
        const brandAfter = cardAfter.locator('details').first();
        await brandAfter.locator('summary').click();
        await expect(brandAfter.getByPlaceholder(/buchhaltung@reisinger/i)).toHaveValue(uniqueEmail);
    });

    test('Normaler Admin sieht die Markeneinstellungen nicht', { tag: ['@feature:admin:brand-settings'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const adminUser = await helper.createIsolatedUser('admin');
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        await expect(page.getByTestId('brand-settings-card')).toHaveCount(0);
    });
});
