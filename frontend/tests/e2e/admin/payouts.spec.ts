import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Payout System UI Workflow', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };
    let photogUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
        photogUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin calculates pool, Photographer views statements', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // --- 1. SUPER ADMIN FLOW ---
        await auth.login(superAdmin.email, superAdmin.password);
        
        await sidebar.navigateTo('Payouts & Abrechnung');
        await expect(page.locator('h1:has-text("Abrechnungen (Payouts)")')).toBeVisible();

        // Neuen Abrechnungslauf starten
        await page.fill('input[placeholder="z.B. 2500.00"]', '100.50');
        await page.getByRole('button', { name: 'Berechnen' }).click();
        
        // Confirm Modal
        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Berechnen' }).click();

        await expect(page.locator('.toast')).toContainText('Abrechnung erfolgreich durchgeführt!');

        // Der 100,50 € Pool sollte nun in der Liste sichtbar sein
        await expect(page.locator('div.font-mono.text-right').filter({ hasText: '100.50 €' }).first()).toBeVisible();

        await auth.logout();

        // --- 2. PHOTOGRAPHER FLOW ---
        await auth.login(photogUser.email, photogUser.password);
        
        await sidebar.navigateTo('Meine Abrechnungen');
        await expect(page.locator('h1:has-text("Meine Abrechnungen")')).toBeVisible();

        // Da in diesem Test keine Downloads simuliert wurden, erwarten wir eine leere Liste
        await expect(page.locator('td', { hasText: 'Du hast bisher noch keine Abrechnungen erhalten.' })).toBeVisible();
    });
});
