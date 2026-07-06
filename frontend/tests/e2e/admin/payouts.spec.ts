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

    test('Super-Admin calculates pool, Photographer views statements', { tags: ['@feature:admin:payouts'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);

        await sidebar.navigateTo('Payouts & Abrechnung');
        await expect(page.locator('h1:has-text("Abrechnungen (Payouts)")')).toBeVisible();

        await page.fill('input[placeholder="z.B. 2500.00"]', '100.50');
        await page.getByRole('button', { name: 'Berechnen' }).click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Berechnen' }).click();

        await expect(page.locator('.toast')).toContainText('Abrechnung erfolgreich durchgeführt!');

        await expect(page.locator('div.font-mono.text-right').filter({ hasText: '100.50 €' }).first()).toBeVisible();

        await auth.logout();

        await auth.login(photogUser.email, photogUser.password);

        await sidebar.navigateTo('Meine Abrechnungen');
        await expect(page.locator('h1:has-text("Meine Abrechnungen")')).toBeVisible();

        await expect(page.locator('td', { hasText: 'Du hast bisher noch keine Abrechnungen erhalten.' })).toBeVisible();
    });

    test('Payout calculation with negative amount is rejected', { tags: ['@feature:admin:payouts'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Payouts & Abrechnung');
        await expect(page.locator('h1:has-text("Abrechnungen (Payouts)")')).toBeVisible();

        await page.fill('input[placeholder="z.B. 2500.00"]', '-100');
        await page.getByRole('button', { name: 'Berechnen' }).click();

        await expect(page.locator('.toast')).toContainText('Bitte einen gültigen Betrag eingeben.');
    });

    test('Photographer can see detailed breakdown', { tags: ['@feature:admin:payouts'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.navigateTo('Meine Abrechnungen');
        await expect(page.locator('h1:has-text("Meine Abrechnungen")')).toBeVisible();

        await expect(page.locator('td', { hasText: 'Du hast bisher noch keine Abrechnungen erhalten.' })).toBeVisible();
    });
});
