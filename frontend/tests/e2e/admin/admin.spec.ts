import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';



test.describe('Admin Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin can manage users and roles', async ({ page, request }) => {
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Benutzer & Rechte');
        await expect(page.locator('h1:has-text("Benutzer & Rechte")')).toBeVisible();

        const uniqueEmail = `e2e-client-${Math.random().toString(36).substring(2, 10)}@example.com`;

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        const form = new FormHelper(page, modal);
        await form.fillUserModal({ name: 'Test Admin Client', email: uniqueEmail });
        
        await modal.clickButton('Nutzer anlegen & Einladen');

        const toast = page.locator('.toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Nutzer angelegt');
        
        await expect(modal.activeModal).toBeHidden();

        // Track user for cleanup
        const usersRes = await request.get('/api/management/users').catch(() => null);
        if (usersRes && usersRes.ok()) {
            const usersData = await usersRes.json();
            const usersList = Array.isArray(usersData) ? usersData : usersData.data;
            const createdUser = usersList?.find((u: { email: string }) => u.email === uniqueEmail);
            if (createdUser) helper.trackUser(createdUser.id);
        }

        await page.fill('input[placeholder="Nutzer suchen (Name oder E-Mail)..."]', uniqueEmail);
        await expect(page.locator(`td:has-text("${uniqueEmail}")`)).toBeVisible();

        await page.locator('tr').filter({ hasText: uniqueEmail }).locator('button', { hasText: 'Bearbeiten' }).click();
        await expect(modal.activeModal.locator('h3:has-text("Test Admin Client bearbeiten")')).toBeVisible();
        await modal.clickButton('Abbrechen');
    });

    test('Admin can access settings', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();
    });

    test('Header Live-Search dropdown appears and handles navigation', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        // Nach Login bereits auf Startseite — kein page.goto('/') nötig
        const headerSearchInput = page.locator('header input[placeholder="Suche in allen Galerien..."]');
        await expect(headerSearchInput).toBeVisible();

        await headerSearchInput.fill('A');
        await expect(page.locator('text=Suche nach "A"')).toBeVisible();

        await headerSearchInput.fill('Ab');
        await expect(page.locator('text=Suche nach "Ab"')).toBeVisible();

        await page.locator('text=Suche nach "Ab"').click();
        
        await expect(page).toHaveURL(/.*\/search\?q=Ab/);
        const searchViewInput = page.locator('input[placeholder="Suche in allen Galerien..."]');
        await expect(searchViewInput).toHaveValue('Ab');
    });
});