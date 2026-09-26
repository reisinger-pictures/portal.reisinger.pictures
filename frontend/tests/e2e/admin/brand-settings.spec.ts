import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ToastHelper } from '../helpers/ToastHelper';

test.describe('Brand Settings (Markeneinstellungen)', () => {
    test.describe.configure({ mode: 'serial' });

    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };
    let shouldResetBrandSettings = false;

    test.beforeEach(async ({ request }) => {
        shouldResetBrandSettings = false;
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        try {
            if (shouldResetBrandSettings) await helper.resetBrandSettings();
        } finally {
            if (helper) await helper.teardown();
        }
    });

    test('Super-Admin kann Markeneinstellungen bearbeiten; Farben und Werte bleiben nach Reload erhalten', { tag: ['@feature:admin:brand-settings'] }, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name === 'Mobile Chrome', 'Globale rp-Einstellungen werden nur einmal im Desktop-Worker verändert');
        shouldResetBrandSettings = true;
        await helper.seedBrandSettings();

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        const card = main.getByTestId('brand-settings-card');
        await expect(card).toBeVisible();

        const brand = card.locator('details').first();
        await brand.locator('summary').click();

        const uniqueEmail = `e2e-brand-${Math.random().toString(36).substring(2, 8)}@example.com`;
        const colorSuffix = Math.random().toString(16).slice(2, 8).padEnd(6, '0');
        const secondaryColorSuffix = Math.random().toString(16).slice(2, 8).padEnd(6, '0');
        const primaryColor = `#${colorSuffix}`;
        const secondaryColor = `#${secondaryColorSuffix}`;
        const emailInput = brand.getByRole('textbox', { name: 'Buchhaltungs-E-Mail', exact: true });
        const primaryColorInput = brand.getByRole('textbox', { name: 'Primärfarbe (Hex)' });
        const secondaryColorInput = brand.getByRole('textbox', { name: 'Sekundärfarbe (Hex)' });

        // SWR-Hydrierungs-Delay abwarten
        await expect(emailInput).toBeVisible({ timeout: 10000 });
        await expect(primaryColorInput).toBeVisible({ timeout: 10000 });
        await expect(secondaryColorInput).toBeVisible({ timeout: 10000 });

        await emailInput.fill(uniqueEmail);
        await primaryColorInput.fill(primaryColor);
        await secondaryColorInput.fill(secondaryColor);
        await brand.getByRole('button', { name: 'Speichern' }).click();

        await new ToastHelper(page).expectToast('Markeneinstellungen gespeichert');
        const html = page.locator('html');
        await expect(html).toHaveCSS('--color-primary', primaryColor);
        await expect(html).toHaveCSS('--color-secondary', secondaryColor);

        await page.reload();
        const mainAfter = page.getByRole('main');
        await expect(mainAfter.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        const cardAfter = mainAfter.getByTestId('brand-settings-card');
        await expect(cardAfter).toBeVisible();
        const brandAfter = cardAfter.locator('details').first();
        await brandAfter.locator('summary').click();
        await expect(brandAfter.getByRole('textbox', { name: 'Buchhaltungs-E-Mail', exact: true })).toHaveValue(uniqueEmail);
        await expect(brandAfter.getByRole('textbox', { name: 'Primärfarbe (Hex)' })).toHaveValue(primaryColor);
        await expect(brandAfter.getByRole('textbox', { name: 'Sekundärfarbe (Hex)' })).toHaveValue(secondaryColor);
        await expect(html).toHaveCSS('--color-primary', primaryColor);
        await expect(html).toHaveCSS('--color-secondary', secondaryColor);
    });

    test('Normaler Admin sieht die Markeneinstellungen nicht', { tag: ['@feature:admin:brand-settings'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const adminUser = await helper.createIsolatedUser('admin');
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Einstellungen');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        await expect(main.getByTestId('brand-settings-card')).toHaveCount(0);
    });
});
