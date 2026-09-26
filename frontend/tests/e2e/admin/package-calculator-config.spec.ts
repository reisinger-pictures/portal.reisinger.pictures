import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ToastHelper } from '../helpers/ToastHelper';

test.describe('Package Calculator Configuration (G2)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    /**
     * The calculator settings are global, brand-scoped state, not per-user.
     * The verification test used to depend on the configuration test having run
     * first and saved them, which is a hidden order dependency: under parallel
     * workers another test can overwrite the values between the save and the
     * read, and the assertion then sees a different price. Observed as 319.00
     * EUR instead of 229.00 EUR, with the locator resolving stably 14 times, so
     * it was never a timing problem.
     *
     * Every test now establishes the values it relies on itself.
     */
    const applyCalculatorSettings = async (page: Page) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const cardBody = page.locator('main h2:has-text("Paket-Rechner Konfiguration")').first()
            .locator('..').locator('..');
        await expect(cardBody).toBeVisible();

        await cardBody.locator('.form-control').filter({ hasText: 'Stundensatz' }).locator('input[type="number"]').fill('95');
        await cardBody.locator('.form-control').filter({ hasText: 'Outdoor-Bilder' }).locator('input[type="number"]').fill('12');
        await cardBody.locator('.form-control').filter({ hasText: 'Reportage-Aufschlag' }).locator('input[type="number"]').fill('25');

        await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
        await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');
    };

    test.beforeEach(async ({ request, page }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
        await applyCalculatorSettings(page);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can configure package calculator settings', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
        // Settings are written by beforeEach so this test does not depend on
        // ordering. It asserts that what was saved is read back.
        const cardBody = page.locator('main h2:has-text("Paket-Rechner Konfiguration")').first()
            .locator('..').locator('..');

        await expect(cardBody.locator('.form-control').filter({ hasText: 'Stundensatz' }).locator('input[type="number"]'))
            .toHaveValue('95');
        await expect(cardBody.locator('.form-control').filter({ hasText: 'Outdoor-Bilder' }).locator('input[type="number"]'))
            .toHaveValue('12');
        await expect(cardBody.locator('.form-control').filter({ hasText: 'Reportage-Aufschlag' }).locator('input[type="number"]'))
            .toHaveValue('25');
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
        await cardBody.locator('.form-control').filter({ hasText: 'Outdoor-Bilder' }).locator('input[type="number"]').fill('20');
        await cardBody.locator('.form-control').filter({ hasText: 'Bilder pro Stunde' }).locator('input[type="number"]').fill('6');

        await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
        await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');

        // Wait for the form to reflect the saved hourly rate (ensures SWR cache is fresh)
        await expect(cardBody.locator('.form-control').filter({ hasText: 'Stundensatz' }).locator('input[type="number"]')).toHaveValue('80');

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
        // Base 50 + Time 120 + (Images (80/20)*15 = 60) = 230 → psych 229
        const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
        await expect(itemTitleInput).toHaveValue('Individuelles Shooting-Paket', { timeout: 10000 });

        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('229.00 €');
    });
});
