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

    test('Admin can configure package calculator settings', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
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

        const outdoorFactorInput = cardBody.locator('.form-control').filter({ hasText: 'Outdoor-Faktor' }).locator('input[type="number"]');
        await outdoorFactorInput.fill('65');

        const flatrateSurchargeInput = cardBody.locator('.form-control').filter({ hasText: 'Reportage-Aufschlag' }).locator('input[type="number"]');
        await flatrateSurchargeInput.fill('25');

        await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();

        await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');
    });

    test('Admin can set outdoor multiplier and verify it in the shooting calculator', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Log in and save all calculator settings via the UI form to ensure correct brand scope
        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Einstellungen');

        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const calculatorCard = page.locator('main h2:has-text("Paket-Rechner Konfiguration")').first();
        await expect(calculatorCard).toBeVisible();
        const cardBody = calculatorCard.locator('..').locator('..');

        await cardBody.locator('.form-control').filter({ hasText: 'Grundpreis' }).locator('input[type="number"]').fill('50');
        await cardBody.locator('.form-control').filter({ hasText: 'Stundensatz' }).locator('input[type="number"]').fill('80');
        await cardBody.locator('.form-control').filter({ hasText: 'Outdoor-Faktor' }).locator('input[type="number"]').fill('30');
        await cardBody.locator('.form-control').filter({ hasText: 'Bilder pro Stunde' }).locator('input[type="number"]').fill('6');

        await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
        await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');

        // Navigate to manual offer page and open calculator
        await sidebar.navigateTo('Manuelles Angebot');

        await page.locator('button:has-text("Paket-Kalkulator")').click();
        const calcModal = page.locator('.modal-open');
        await expect(calcModal).toBeVisible();

        // Enter values: 90 min, 15 images
        await calcModal.locator('.form-control', { hasText: 'Dauer (Min.)' }).locator('input').fill('90');
        await calcModal.locator('.form-control', { hasText: 'Inkl. Bilder' }).locator('input').fill('15');

        // Activate outdoor
        await calcModal.locator('label').filter({ hasText: 'Outdoor-Shooting' }).locator('input[type="checkbox"]').check();

        // Calculate & add
        await calcModal.getByRole('button', { name: 'Berechnen & Hinzufügen' }).click();
        await expect(calcModal).toBeHidden();

        // Verify result:
        // Base 50 + Time 120 + (Images 200 * 0.3 = 60) = 230 → psych 229
        const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
        await expect(itemTitleInput).toHaveValue('Individuelles Shooting-Paket', { timeout: 10000 });

        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('229.00 €');
    });
});
