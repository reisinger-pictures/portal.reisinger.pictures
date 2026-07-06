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

    test('Admin can configure package calculator settings', { tags: ['@feature:admin:calculator'] }, async ({ page }) => {
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

    test('Admin can set outdoor multiplier and verify it in the shooting calculator', { tags: ['@feature:admin:calculator'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Outdoor-Faktor per API setzen (0.3 = 30%)
        await request.put('/api/management/settings/license-terms', {
            data: {
                calc_base_price: 50,
                calc_hourly_rate: 80,
                calc_images_per_hour: 6,
                calc_outdoor_multiplier: 0.3,
                mult_commercial: 2.0,
                mult_unlimited: 1.5,
                mult_international: 1.5
            },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Manuelles Angebot');

        // Kalkulator Modal öffnen
        await page.locator('button:has-text("Paket-Kalkulator")').click();
        const calcModal = page.locator('.modal-open');
        await expect(calcModal).toBeVisible();

        // Werte eintragen: 90 Min, 15 Bilder
        await calcModal.locator('.form-control', { hasText: 'Dauer (Min.)' }).locator('input').fill('90');
        await calcModal.locator('.form-control', { hasText: 'Inkl. Bilder' }).locator('input').fill('15');

        // Outdoor aktivieren
        await calcModal.locator('label').filter({ hasText: 'Outdoor-Shooting' }).locator('input[type="checkbox"]').check();

        // Berechnen & Hinzufügen klicken
        await calcModal.getByRole('button', { name: 'Berechnen & Hinzufügen' }).click();
        await expect(calcModal).toBeHidden();

        // Ergebnis validieren:
        // Base 50 + Time 120 + (Images 200 * 0.3 = 60) = 230 → psych 229
        const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
        await expect(itemTitleInput).toHaveValue('Individuelles Shooting-Paket', { timeout: 10000 });

        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('229.00 €');
    });
});
