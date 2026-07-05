import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ToastHelper } from '../helpers/ToastHelper';

test.describe('Package Calculator Configuration (G2)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can configure package calculator settings', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Einstellungen');

        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const calculatorCard = page.locator('main h2:has-text("Paket-Rechner Konfiguration")').first();
        await expect(calculatorCard).toBeVisible();

        const cardBody = calculatorCard.locator('..').locator('..');
        const hourlyRateInput = cardBody.locator('.form-control').filter({ hasText: 'Stundensatz' }).locator('input[type="number"]');
        await hourlyRateInput.fill('95');

        await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();

        await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');
    });
});
