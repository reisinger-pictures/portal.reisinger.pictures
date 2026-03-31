import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.afterAll(async ({ request }) => {
  await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('Admin Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'admin');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin can manage users and roles', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Benutzer & Rechte');
        await expect(page.locator('h1:has-text("Benutzer & Rechte")')).toBeVisible();

        const uniqueEmail = `e2e-client-${Date.now()}@example.com`;

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        await modal.fillInputByLabel('Name', 'Test Admin Client');
        await modal.fillInputByLabel('E-Mail Adresse', uniqueEmail);
        
        // Warte auf den SWR-Refetch nach dem Anlegen
        const usersRefetchPromise = page.waitForResponse(res => 
            res.url().includes('/api/management/users') && res.request().method() === 'GET'
        );

        await modal.clickButton('Nutzer anlegen & Einladen');
        await usersRefetchPromise;

        const toast = page.locator('.toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Nutzer angelegt');
        
        await expect(modal.activeModal).toBeHidden();

        await page.fill('input[placeholder="Nutzer suchen (Name oder E-Mail)..."]', uniqueEmail);
        await expect(page.locator(`td:has-text("${uniqueEmail}")`)).toBeVisible();

        await page.locator('tr').filter({ hasText: uniqueEmail }).locator('button', { hasText: 'Bearbeiten' }).click();
        await expect(modal.activeModal.locator('h3:has-text("Test Admin Client bearbeiten")')).toBeVisible();
        await modal.clickButton('Abbrechen');
    });

    test('Admin can access settings', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("Einstellungen")')).toBeVisible();
    });

    test('Header Live-Search dropdown appears and handles navigation', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        await page.goto('/');
        const headerSearchInput = page.locator('header input[placeholder="Suche in allen Galerien..."]');
        await expect(headerSearchInput).toBeVisible();

        await headerSearchInput.fill('A');
        await expect(page.locator('text=Suche nach "A"')).toBeHidden();

        await headerSearchInput.fill('Ab');
        await expect(page.locator('text=Suche nach "Ab"')).toBeVisible();

        await page.locator('text=Suche nach "Ab"').click();
        
        await expect(page).toHaveURL(/.*\/search\?q=Ab/);
        const searchViewInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchViewInput).toHaveValue('Ab');
    });
});